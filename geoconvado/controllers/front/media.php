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

use GeoConvado\Service\MediaStreamer;
use GeoConvado\Service\ReviewRepository;

/** Liefert Foto/Video/Poster einer Bewertung aus. Öffentlich nur nach Freigabe; der Verfasser sieht seine eigenen Dateien immer. */
class GeoConvadoMediaModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        if (!(int) Configuration::get('GEOCONVADO_RV_ON')) {
            $this->notFound();
        }
        $m = ReviewRepository::findMedia((int) Tools::getValue('id'));
        if (!$m) {
            $this->notFound();
        }
        $public = $m['review_status'] === 'approved';
        $owner = $this->context->customer->isLogged() && (int) $this->context->customer->id === (int) $m['id_customer'];
        if (!$public && !$owner) {
            $this->notFound();
        }
        $file = MediaStreamer::pick($m, Tools::getValue('v') === 't' ? 't' : 'f');
        if ($file === '') {
            $this->notFound();
        }
        header('X-Robots-Tag: noindex');
        MediaStreamer::send($file, $public, (string) ($_SERVER['HTTP_RANGE'] ?? ''));
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
}
