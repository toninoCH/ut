<?php
/**
 * GEO.Convado - AI visibility (llms.txt) for PrestaShop 9
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from Convado AG.
 * Use, copy, modification or distribution is only permitted with a valid
 * license agreement.
 *
 * @author    Convado AG <info@convado.ch>
 * @copyright 2026 Convado AG
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use GeoConvado\Service\MediaException;
use GeoConvado\Service\MediaProcessor;
use GeoConvado\Service\ReviewEligibility;
use GeoConvado\Service\ReviewGuard;
use GeoConvado\Service\ReviewRepository;
use GeoConvado\Service\ReviewText;
use GeoConvado\Service\VideoProcessor;
use GeoConvado\Service\VoucherService;

/**
 * Käuferbewertung: Formular, Absenden, Hilfreich/Melden, Löschen.
 * Schreiben dürfen nur eingeloggte Kunden mit versendeter, nicht zurückgegebener Bestellung dieses Artikels.
 */
class GeoConvadoReviewModuleFrontController extends ModuleFrontController
{
    /** @var array<string, mixed> Eingaben für die erneute Anzeige des Formulars nach einem Fehler */
    private array $old = [];

    public function init(): void
    {
        parent::init();
        if (!(int) Configuration::get('GEOCONVADO_RV_ON')) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }
    }

    private function t(): array
    {
        return ReviewText::get((string) $this->context->language->iso_code);
    }

    private function product(): Product
    {
        $p = new Product((int) Tools::getValue('id_product'), false, (int) $this->context->language->id, (int) $this->context->shop->id);
        if (!Validate::isLoadedObject($p) || !$p->active) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }

        return $p;
    }

    private function productUrl(Product $p, string $flash = ''): string
    {
        $u = $this->context->link->getProductLink($p);

        return $u . ($flash !== '' ? (str_contains($u, '?') ? '&' : '?') . 'geo_rv=' . $flash : '') . '#geo-reviews';
    }

    private function loginUrl(): string
    {
        $back = $this->context->link->getModuleLink('geoconvado', 'review', ['id_product' => (int) Tools::getValue('id_product')], true);

        return $this->context->link->getPageLink('authentication', true, null, ['back' => $back]);
    }

    private function tokenOk(): bool
    {
        return hash_equals(Tools::getToken(false), (string) Tools::getValue('token'));
    }

    /* --------------------------------------------------------------- POST */

    public function postProcess(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $do = (string) Tools::getValue('do');
        if (!in_array($do, ['submit', 'vote', 'report', 'delete'], true)) {
            return;
        }
        $p = $this->product();
        if (!$this->tokenOk()) {
            $this->errors[] = $this->t()['e_token'];

            return;
        }
        match ($do) {
            'submit' => $this->submit($p),
            'vote', 'report' => $this->flag($p, $do),
            'delete' => $this->deleteOwn($p),
        };
    }

    private function flag(Product $p, string $type): void
    {
        $id = (int) Tools::getValue('id_review');
        $r = ReviewRepository::find($id);
        if (!$r || $r['status'] !== 'approved' || (int) $r['id_product'] !== (int) $p->id) {
            Tools::redirect($this->productUrl($p));
        }
        $logged = $this->context->customer->isLogged();
        if ($type === 'vote') {
            if (!$logged) {
                Tools::redirect($this->loginUrl());
            }
            if ((int) $r['id_customer'] !== (int) $this->context->customer->id) {
                ReviewRepository::flag($id, 'c' . (int) $this->context->customer->id, 'helpful');
            }
            Tools::redirect($this->productUrl($p));
        }
        $voter = $logged ? 'c' . (int) $this->context->customer->id : 'g' . substr(ReviewGuard::ipHash((string) Tools::getRemoteAddr()), 0, 20);
        ReviewRepository::flag($id, $voter, 'report');
        Tools::redirect($this->productUrl($p, 'reported'));
    }

    private function deleteOwn(Product $p): void
    {
        if (!$this->context->customer->isLogged()) {
            Tools::redirect($this->loginUrl());
        }
        $r = ReviewRepository::find((int) Tools::getValue('id_review'));
        if ($r && (int) $r['id_customer'] === (int) $this->context->customer->id) {
            ReviewRepository::delete((int) $r['id_review']);
            Tools::redirect($this->productUrl($p, 'deleted'));
        }
        Tools::redirect($this->productUrl($p));
    }

    private function submit(Product $p): void
    {
        $t = $this->t();
        $cust = $this->context->customer;
        if (!$cust->isLogged()) {
            Tools::redirect($this->loginUrl());
        }
        $idCustomer = (int) $cust->id;
        $idShop = (int) $this->context->shop->id;
        $this->old = ['rating' => (int) Tools::getValue('rating'), 'title' => (string) Tools::getValue('title'), 'body' => (string) Tools::getValue('body'), 'consent' => (int) (bool) Tools::getValue('consent')];

        // Zeitfalle und Formular-Token
        if (!ReviewGuard::verifyToken((string) Tools::getValue('ftoken'), (int) $p->id, $idCustomer)) {
            $this->errors[] = $t['e_token'];

            return;
        }
        // Honigtopf: Menschen sehen das Feld nicht. Bots bekommen scheinbar Erfolg, es wird nichts gespeichert.
        if ((string) Tools::getValue('website') !== '') {
            Tools::redirect($this->productUrl($p, 'ok'));
        }
        $el = ReviewEligibility::check($idCustomer, (int) $p->id, $idShop);
        if (!$el['ok']) {
            $this->errors[] = $this->eligibilityText($el, $t);

            return;
        }
        $rating = (int) Tools::getValue('rating');
        if ($rating < 1 || $rating > 5) {
            $this->errors[] = $t['e_rating'];

            return;
        }
        $title = mb_substr(ReviewGuard::clean((string) Tools::getValue('title')), 0, 120);
        $body = ReviewGuard::clean((string) Tools::getValue('body'));
        $min = (int) Configuration::get('GEOCONVADO_RV_MIN_TEXT');
        $min = $min < 10 ? 30 : min(1000, $min);
        $max = 3000;
        $block = array_filter(array_map('trim', explode("\n", (string) Configuration::get('GEOCONVADO_RV_BLOCK'))));
        $bad = ReviewGuard::content($title, $body, $min, $max, $block);
        if ($bad !== null) {
            $this->errors[] = match ($bad) {
                'short' => sprintf($t['e_short'], $min),
                'long' => sprintf($t['e_long'], $max),
                'links' => $t['e_links'],
                default => $t['e_spam'],
            };

            return;
        }
        $hash = ReviewGuard::bodyHash($body);
        if (ReviewRepository::duplicate($hash)) {
            $this->errors[] = $t['e_dup'];

            return;
        }
        $ip = ReviewGuard::ipHash((string) Tools::getRemoteAddr());
        $h1 = ReviewRepository::recent($idCustomer, $ip, 1);
        $h24 = ReviewRepository::recent($idCustomer, $ip, 24);
        if ($h1['customer'] >= 3 || $h24['customer'] >= 10 || $h24['ip'] >= 10) {
            $this->errors[] = $t['e_rate'];

            return;
        }

        // Medien zuerst verarbeiten; bei jedem Fehler wird alles wieder entfernt.
        $staged = [];
        $media = [];
        try {
            $this->collectMedia($media, $staged);
        } catch (MediaException $e) {
            $this->cleanup($staged);
            $this->errors[] = sprintf($t['e_media'], Tools::safeOutput($this->lastFile), $t[$e->getMessage()] ?? $t['m_fail']);

            return;
        }

        $photos = count(array_filter($media, static fn (array $m): bool => $m['kind'] === 'image'));
        $incentivised = VoucherService::qualifies(mb_strlen($body), $photos);
        $id = ReviewRepository::create([
            'id_shop' => $idShop, 'id_product' => (int) $p->id, 'id_customer' => $idCustomer, 'id_order' => $el['id_order'],
            'id_lang' => (int) $this->context->language->id, 'rating' => $rating, 'title' => $title, 'body' => $body,
            'display_name' => ReviewGuard::displayName((string) $cust->firstname, (string) $cust->lastname),
            'status' => 'pending', 'incentivised' => $incentivised, 'consent_quote' => (bool) Tools::getValue('consent'),
            'body_hash' => $hash, 'ip_hash' => $ip,
        ]);
        if ($id <= 0) {
            $this->cleanup($staged);
            $this->errors[] = $t['e_generic'];

            return;
        }
        foreach ($media as $m) {
            if (ReviewRepository::addMedia($id, $m) <= 0) {
                ReviewRepository::delete($id);
                $this->cleanup($staged);
                $this->errors[] = $t['e_generic'];

                return;
            }
        }
        if ((int) Configuration::get('GEOCONVADO_RV_AUTO') === 1 && $media === []) {
            // Automatische Freigabe nur ohne Medien (Medien prüft immer ein Mensch).
            ReviewRepository::setStatus($id, 'approved');
            GeoConvado::markDirty();
            if ($incentivised) {
                VoucherService::issue($id);
            }
        }
        Tools::redirect($this->productUrl($p, $incentivised ? 'okv' : 'ok'));
    }

    /** @var string Name der zuletzt verarbeiteten Datei (für Fehlermeldungen) */
    private string $lastFile = '';

    /**
     * @param array<int, array<string, mixed>> $media
     * @param string[] $staged
     *
     * @throws MediaException
     */
    private function collectMedia(array &$media, array &$staged): void
    {
        $maxImg = max(0, min(6, (int) Configuration::get('GEOCONVADO_RV_IMG_MAX')));
        $files = self::files('photos');
        if (count($files) > $maxImg) {
            throw new MediaException('m_count');
        }
        foreach ($files as $f) {
            $this->lastFile = mb_substr((string) $f['name'], 0, 40);
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new MediaException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'm_size' : 'm_upload');
            }
            $r = MediaProcessor::image((string) $f['tmp_name'], (int) $f['size']);
            $staged[] = $r['file'];
            $staged[] = $r['thumb'];
            $media[] = ['kind' => 'image', 'file' => $r['file'], 'thumb' => $r['thumb'], 'mime' => $r['mime'], 'bytes' => $r['bytes'], 'w' => $r['w'], 'h' => $r['h'], 'state' => 'ready'];
        }
        $video = self::files('video');
        if ($video !== []) {
            $f = $video[0];
            $this->lastFile = mb_substr((string) $f['name'], 0, 40);
            if (!(int) Configuration::get('GEOCONVADO_RV_VIDEO_ON')) {
                throw new MediaException('m_off');
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new MediaException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'm_size' : 'm_upload');
            }
            $r = VideoProcessor::accept((string) $f['tmp_name'], (int) $f['size'], max(1, (int) Configuration::get('GEOCONVADO_RV_VIDEO_MB')) * 1048576, max(1, (int) Configuration::get('GEOCONVADO_RV_VIDEO_SEC')));
            $staged[] = $r['file'];
            $staged[] = $r['src'];
            $media[] = ['kind' => 'video', 'file' => $r['file'], 'src' => $r['src'], 'mime' => $r['mime'], 'bytes' => $r['bytes'], 'duration' => $r['duration'], 'state' => $r['state']];
        }
    }

    /** @param string[] $names */
    private function cleanup(array $names): void
    {
        foreach ($names as $n) {
            if ($n !== '') {
                MediaProcessor::remove($n);
            }
        }
    }

    /** @return array<int, array{name: string, tmp_name: string, size: int, error: int}> */
    private static function files(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $out = [];
        foreach ((array) $f['name'] as $i => $name) {
            $err = (int) (is_array($f['error']) ? $f['error'][$i] : $f['error']);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string) $name,
                'tmp_name' => (string) (is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name']),
                'size' => (int) (is_array($f['size']) ? $f['size'][$i] : $f['size']),
                'error' => $err,
            ];
        }

        return $out;
    }

    private function eligibilityText(array $el, array $t): string
    {
        return match ($el['reason']) {
            'already' => $t['already'],
            'too_early' => sprintf($t['too_early'], $el['days']),
            'returned' => $t['returned'],
            default => $t['only_buyers'],
        };
    }

    /* ---------------------------------------------------------------- GET */

    public function initContent(): void
    {
        parent::initContent();
        header('X-Robots-Tag: noindex, nofollow');
        $t = $this->t();
        $p = $this->product();
        $cust = $this->context->customer;
        if (!$cust->isLogged()) {
            Tools::redirect($this->loginUrl());
        }
        $idShop = (int) $this->context->shop->id;
        $el = ReviewEligibility::check((int) $cust->id, (int) $p->id, $idShop);
        $iso = (string) $this->context->language->iso_code;
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $cur = (new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code;
        $amount = (float) Configuration::get('GEOCONVADO_RV_VOUCHER_AMOUNT');
        $minChars = max(0, (int) Configuration::get('GEOCONVADO_RV_MIN_CHARS'));
        $photoReq = (int) Configuration::get('GEOCONVADO_RV_PHOTO_REQ') === 1;
        $caps = MediaProcessor::caps();
        $imgMax = max(0, min(6, (int) Configuration::get('GEOCONVADO_RV_IMG_MAX')));
        $videoOn = (int) Configuration::get('GEOCONVADO_RV_VIDEO_ON') === 1;
        $imagesOn = MediaProcessor::imagesPossible() && $imgMax > 0;

        $this->context->smarty->assign([
            'geo_t' => $t,
            'geo_ok' => $el['ok'],
            'geo_reason' => $el['ok'] ? '' : $this->eligibilityText($el, $t),
            'geo_product' => ['id' => (int) $p->id, 'name' => (string) $p->name, 'url' => $this->productUrl($p)],
            'geo_heading' => sprintf($t['f_heading'], (string) $p->name),
            'geo_name_note' => sprintf($t['f_name'], ReviewGuard::displayName((string) $cust->firstname, (string) $cust->lastname)),
            'geo_consent_text' => sprintf($t['f_consent'], $shopName),
            'geo_voucher' => VoucherService::enabled(),
            'geo_offer' => sprintf($t['f_offer'], $cur . ' ' . number_format($amount, 2, '.', ''), (int) Configuration::get('GEOCONVADO_RV_VOUCHER_DAYS'), $cur . ' ' . number_format((float) Configuration::get('GEOCONVADO_RV_VOUCHER_MIN'), 2, '.', '')),
            'geo_cond' => sprintf($t['f_cond'], $minChars, $photoReq ? $t['f_cond_photo'] : ''),
            'geo_min_chars' => $minChars,
            'geo_images_on' => $imagesOn,
            'geo_photos_label' => sprintf($t['f_photos'], $imgMax),
            'geo_video_on' => $videoOn && $caps['file_uploads'] && $caps['root_writable'],
            'geo_video_label' => sprintf($t['f_video'], (int) Configuration::get('GEOCONVADO_RV_VIDEO_SEC'), (int) Configuration::get('GEOCONVADO_RV_VIDEO_MB')),
            'geo_form_action' => $this->context->link->getModuleLink('geoconvado', 'review', [], true),
            'geo_token' => Tools::getToken(false),
            'geo_ftoken' => ReviewGuard::formToken((int) $p->id, (int) $cust->id, time()),
            'geo_old' => $this->old + ['rating' => 0, 'title' => '', 'body' => '', 'consent' => 0],
            'geo_max_file' => max(1, (int) floor(min($caps['upload_max'] > 0 ? $caps['upload_max'] : PHP_INT_MAX, MediaProcessor::IMG_MAX_BYTES) / 1048576)),
            'geo_lang_iso' => $iso,
            'geo_star_vals' => [5, 4, 3, 2, 1],
        ]);
        $this->setTemplate('module:geoconvado/views/templates/front/review_form.tpl');
    }
}
