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

use GeoConvado\Service\Generator;

class GeoConvadoCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $token = (string) Tools::getValue('token');
        $expected = (string) Configuration::get('GEOCONVADO_CRON_TOKEN');
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'error' => 'Ungültiges Token']));
        }

        if ((string) Configuration::get('GEOCONVADO_CRON_ON') === '0') {
            exit(json_encode(['success' => false, 'error' => 'Cron im Modul ausgeschaltet']));
        }

        @set_time_limit(300);
        @ignore_user_abort(true);

        try {
            $result = (new Generator($this->context))->run(20, (bool) Tools::getValue('force'));
            GeoConvado\Service\Tracker::aggregate();
            if ((int) Configuration::get('GEOCONVADO_RV_ON')) {
                $result['reviews'] = [
                    'videos' => GeoConvado\Service\VideoProcessor::processQueue(1, max(10, (int) Configuration::get('GEOCONVADO_RV_VIDEO_SEC'))),
                ];
                GeoConvado\Service\ReviewRepository::purge();
            }
            Configuration::updateGlobalValue('GEOCONVADO_LAST_CRON', date('Y-m-d H:i:s'));
            exit(json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('GEO.Convado Cron: ' . $e->getMessage(), 3, null, 'geoconvado');
            http_response_code(500);
            exit(json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }
}
