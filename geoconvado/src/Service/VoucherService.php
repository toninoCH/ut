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

namespace GeoConvado\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Dankes-Gutschein für freigegebene Bewertungen (Warenkorbregel, an den Kunden gebunden, einmalig). */
final class VoucherService
{
    public static function enabled(): bool
    {
        return (int) \Configuration::get('GEOCONVADO_RV_VOUCHER_ON') === 1 && (float) \Configuration::get('GEOCONVADO_RV_VOUCHER_AMOUNT') > 0;
    }

    /** Erfüllt eine Bewertung heute die Bedingungen für den Gutschein? (Länge, Foto) */
    public static function qualifies(int $chars, int $photos): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $min = max(0, (int) \Configuration::get('GEOCONVADO_RV_MIN_CHARS'));
        $needPhoto = (int) \Configuration::get('GEOCONVADO_RV_PHOTO_REQ') === 1;

        return $chars >= $min && (!$needPhoto || $photos >= 1);
    }

    /**
     * Gutschein für eine freigegebene Bewertung ausstellen. Höchstens einmal je Bewertung.
     *
     * @return array{ok: bool, code: string, error: string}
     */
    public static function issue(int $idReview): array
    {
        $fail = static fn (string $e): array => ['ok' => false, 'code' => '', 'error' => $e];
        if (!self::enabled()) {
            return $fail('Gutschein ist ausgeschaltet');
        }
        $r = ReviewRepository::find($idReview);
        if (!$r || $r['status'] !== 'approved' || (int) $r['incentivised'] !== 1) {
            return $fail('Bewertung nicht freigegeben oder ohne Gutschein-Anspruch');
        }
        $max = max(1, (int) \Configuration::get('GEOCONVADO_RV_VOUCHER_YEAR_MAX'));
        if (ReviewRepository::vouchersLastYear((int) $r['id_customer']) >= $max) {
            return $fail('Jahreslimit für diesen Kunden erreicht');
        }
        if (!ReviewRepository::claimVoucher($idReview)) {
            return $fail('Gutschein bereits ausgestellt');
        }

        $amount = (float) \Configuration::get('GEOCONVADO_RV_VOUCHER_AMOUNT');
        $days = max(1, (int) \Configuration::get('GEOCONVADO_RV_VOUCHER_DAYS'));
        $min = max(0.0, (float) \Configuration::get('GEOCONVADO_RV_VOUCHER_MIN'));
        $idCur = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $code = 'DANKE-' . strtoupper(bin2hex(random_bytes(4)));

        try {
            $rule = new \CartRule();
            $rule->name = [];
            foreach (\Language::getLanguages(false) as $l) {
                $rule->name[(int) $l['id_lang']] = ReviewText::get((string) $l['iso_code'])['mail_subject'];
            }
            $rule->description = 'GEO.Convado Bewertung #' . $idReview;
            $rule->code = $code;
            $rule->id_customer = (int) $r['id_customer'];
            $rule->date_from = date('Y-m-d H:i:s');
            $rule->date_to = date('Y-m-d H:i:s', time() + $days * 86400);
            $rule->quantity = 1;
            $rule->quantity_per_user = 1;
            $rule->priority = 1;
            $rule->partial_use = false;
            $rule->minimum_amount = $min;
            $rule->minimum_amount_tax = true;
            $rule->minimum_amount_currency = $idCur;
            $rule->minimum_amount_shipping = false;
            $rule->reduction_amount = $amount;
            $rule->reduction_tax = true;
            $rule->reduction_currency = $idCur;
            $rule->reduction_percent = 0;
            $rule->free_shipping = false;
            $rule->highlight = false;
            $rule->active = true;
            if (!$rule->add()) {
                ReviewRepository::setVoucher($idReview, 0);

                return $fail('Warenkorbregel konnte nicht angelegt werden');
            }
        } catch (\Throwable $e) {
            ReviewRepository::setVoucher($idReview, 0);

            return $fail('Warenkorbregel: ' . $e->getMessage());
        }
        ReviewRepository::setVoucher($idReview, (int) $rule->id);

        $sent = self::mail($r, $code, $amount, $days, $min);

        return ['ok' => true, 'code' => $code, 'error' => $sent ? '' : 'E-Mail konnte nicht gesendet werden (Gutschein ist trotzdem angelegt)'];
    }

    private static function mail(array $r, string $code, float $amount, int $days, float $min): bool
    {
        $c = new \Customer((int) $r['id_customer']);
        if (!\Validate::isLoadedObject($c)) {
            return false;
        }
        $idLang = (int) ($c->id_lang ?: $r['id_lang']);
        $iso = (string) \Language::getIsoById($idLang);
        $cur = (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code;
        $fmt = static fn (float $v): string => $cur . ' ' . number_format($v, 2, '.', '');
        $idShop = (int) $r['id_shop'];

        return (bool) \Mail::Send(
            $idLang,
            'review_voucher',
            ReviewText::get($iso)['mail_subject'],
            [
                '{firstname}' => $c->firstname,
                '{voucher_code}' => $code,
                '{voucher_value}' => $fmt($amount),
                '{voucher_days}' => (string) $days,
                '{min_amount}' => $fmt($min),
            ],
            $c->email,
            $c->firstname . ' ' . $c->lastname,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'geoconvado/mails/',
            false,
            $idShop
        );
    }
}
