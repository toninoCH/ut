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

namespace GeoConvado\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use GeoConvado\Service\MediaProcessor;
use GeoConvado\Service\MediaStreamer;
use GeoConvado\Service\ReviewRepository;
use GeoConvado\Service\VoucherService;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Moderation: prüfen, freigeben, ablehnen, löschen, Blog-Link setzen, Gutschein ausstellen. */
class ReviewController extends PrestaShopAdminController
{
    private function allowed(string $mode): bool
    {
        try {
            return (bool) $this->isGranted($mode, 'AdminModules');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function indexAction(Request $request): Response
    {
        if (!$this->allowed('read')) {
            return new Response('Keine Berechtigung', 403);
        }
        \Module::getInstanceByName('geoconvado');
        $status = (string) $request->query->get('status', 'pending');
        $page = max(1, (int) $request->query->get('page', 1));
        $list = ReviewRepository::adminList($status === 'all' ? '' : $status, $page, 25);
        $module = \Module::getInstanceByName('geoconvado');
        $rows = [];
        foreach ($list['rows'] as $r) {
            $media = [];
            foreach ($r['media'] as $m) {
                $media[] = [
                    'kind' => $m['kind'], 'state' => $m['state'],
                    'url' => $this->generateUrl('geoconvado_review_media', ['id' => (int) $m['id_media']]),
                    'thumb' => $this->generateUrl('geoconvado_review_media', ['id' => (int) $m['id_media'], 'v' => 't']),
                ];
            }
            $r['media_view'] = $media;
            $rows[] = $r;
        }

        return $this->render('@Modules/geoconvado/views/templates/admin/reviews.html.twig', [
            'version' => $module instanceof \GeoConvado ? $module->version : '',
            'css_url' => __PS_BASE_URI__ . 'modules/geoconvado/views/css/admin.css?v=' . ($module instanceof \GeoConvado ? $module->version : ''),
            'rows' => $rows, 'total' => $list['total'], 'page' => $page, 'pages' => max(1, (int) ceil($list['total'] / 25)),
            'status' => $status, 'counts' => ReviewRepository::counts(), 'back_url' => $this->generateUrl('geoconvado_config', ['tab' => 'reviews']),
            'reasons' => ['spam' => 'Spam', 'abuse' => 'Missbrauch / Beleidigung', 'pii' => 'Persönliche Daten', 'offtopic' => 'Anderer Artikel / kein Bezug', 'other' => 'Anderes'],
            'flash' => (string) $request->query->get('msg', ''),
        ]);
    }

    public function actionAction(Request $request): Response
    {
        $back = $this->redirectToRoute('geoconvado_reviews', ['status' => (string) $request->request->get('status', 'pending'), 'page' => (int) $request->request->get('page', 1)]);
        if (!$this->allowed('update')) {
            return new Response('Keine Berechtigung', 403);
        }
        if (!$this->isCsrfTokenValid('geoconvado', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('geoconvado_reviews', ['msg' => 'token']);
        }
        \Module::getInstanceByName('geoconvado');
        $id = (int) $request->request->get('id_review');
        $r = $id > 0 ? ReviewRepository::find($id) : null;
        if (!$r) {
            return $back;
        }
        $msg = '';
        switch ((string) $request->request->get('action')) {
            case 'approve':
                ReviewRepository::setStatus($id, 'approved');
                \GeoConvado::markDirty();
                if ((int) $r['incentivised'] === 1 && (int) $r['voucher_id'] === 0) {
                    $v = VoucherService::issue($id);
                    $msg = $v['ok'] ? ($v['error'] === '' ? 'approved_voucher' : 'voucher_mail') : 'voucher_fail:' . $v['error'];
                } else {
                    $msg = 'approved';
                }
                break;
            case 'reject':
                ReviewRepository::setStatus($id, 'rejected', (string) $request->request->get('reason', 'other'));
                \GeoConvado::markDirty();
                $msg = 'rejected';
                break;
            case 'voucher':
                $v = VoucherService::issue($id);
                $msg = $v['ok'] ? ($v['error'] === '' ? 'voucher_ok' : 'voucher_mail') : 'voucher_fail:' . $v['error'];
                break;
            case 'delete':
                ReviewRepository::delete($id);
                \GeoConvado::markDirty();
                $msg = 'deleted';
                break;
            case 'blog':
                ReviewRepository::setBlogUrl($id, (string) $request->request->get('blog_url', ''));
                $msg = 'blog';
                break;
        }

        return $this->redirectToRoute('geoconvado_reviews', ['status' => (string) $request->request->get('status', 'pending'), 'page' => (int) $request->request->get('page', 1), 'msg' => $msg]);
    }

    /** Vorschau von Fotos, Videos und Postern auch vor der Freigabe. */
    public function mediaAction(Request $request): Response
    {
        if (!$this->allowed('read')) {
            return new Response('Keine Berechtigung', 403);
        }
        $m = ReviewRepository::findMedia((int) $request->query->get('id'));
        if (!$m) {
            return new Response('Not found', 404);
        }
        $file = MediaStreamer::pick($m, $request->query->get('v') === 't' ? 't' : 'f');
        if ($file === '') {
            return new Response('Not found', 404);
        }
        try {
            $path = MediaProcessor::path($file);
        } catch (\InvalidArgumentException $e) {
            return new Response('Not found', 404);
        }
        if (!is_file($path)) {
            return new Response('Not found', 404);
        }
        $resp = new BinaryFileResponse($path); // unterstützt Range (Video)
        $resp->headers->set('Content-Type', MediaStreamer::mime($file));
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        $resp->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $resp->headers->set('Cache-Control', 'private, no-store');

        return $resp;
    }
}
