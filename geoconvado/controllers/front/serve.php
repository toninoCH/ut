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

use GeoConvado\Service\BotDetector;
use GeoConvado\Service\Tracker;

class GeoConvadoServeModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        $path = (string) Tools::getValue('path', '');
        $isJson = (string) Tools::getValue('format') === 'json';
        $name = $isJson ? 'llms.json' : 'llms' . $path . '.txt';
        $ok = $isJson || preg_match('#^llms(-full)?(-[a-z]{2})?\.txt$#', $name)
            || preg_match('#^llms/(shop\d+/)?[a-z]{2}/[a-z0-9\-]+\.txt$#', $name)
            || preg_match('#^llms/shop\d+/llms(-full)?-[a-z]{2}\.txt$#', $name);
        $file = _PS_ROOT_DIR_ . '/llms-data/' . $name;

        if (!$ok || !is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Not found');
        }

        $bot = BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        Tracker::log((int) $this->context->shop->id, $bot ?? 'other', 'llms', '/' . $name);

        header($isJson ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        header('X-Robots-Tag: noindex');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT');
        readfile($file);
        exit;
    }
}
