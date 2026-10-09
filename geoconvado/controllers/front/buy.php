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

class GeoConvadoBuyModuleFrontController extends ModuleFrontController
{
    /**
     * GET zeigt nur eine Bestätigungsseite (Bots, Link-Scanner und Vorschauen erzeugen keinen Warenkorb).
     * Erst der Klick des Kunden (POST mit Token) legt den Artikel in den Warenkorb.
     */
    public function initContent(): void
    {
        parent::initContent();
        header('X-Robots-Tag: noindex, nofollow');

        $idProduct = (int) Tools::getValue('id');
        $ipa = (int) Tools::getValue('ipa');
        $qty = max(1, min(20, (int) Tools::getValue('qty', 1)));
        $idLang = (int) $this->context->language->id;
        $product = new Product($idProduct, true, $idLang, (int) $this->context->shop->id);

        if (!Validate::isLoadedObject($product) || !$product->active || !$product->available_for_order
            || !$product->show_price || (int) Configuration::get('PS_CATALOG_MODE') || !Group::getCurrent()->show_prices) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }
        if ($ipa <= 0 || !in_array($ipa, array_map('intval', array_column((array) $product->getAttributeCombinations($idLang), 'id_product_attribute')), true)) {
            $ipa = (int) Product::getDefaultAttribute($idProduct);
        }

        if (Tools::isSubmit('geo_confirm') && Tools::getValue('token') === Tools::getToken(false)) {
            $this->addToCart($idProduct, $ipa, $qty);

            return;
        }

        $bot = GeoConvado\Service\BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        GeoConvado\Service\Tracker::log((int) $this->context->shop->id, $bot ?? 'agent', 'bview', 'view/' . $idProduct . '/' . $ipa . '/' . $qty, $idProduct);

        $cover = Product::getCover($idProduct);
        $this->context->smarty->assign([
            'geo_p' => [
                'name' => (string) $product->name,
                'qty' => $qty,
                'price' => Tools::getContextLocale($this->context)->formatPrice(Product::getPriceStatic($idProduct, true, $ipa ?: null, 2) * $qty, $this->context->currency->iso_code),
                'img' => !empty($cover['id_image']) ? $this->context->link->getImageLink((string) $product->link_rewrite, $idProduct . '-' . (int) $cover['id_image'], ImageType::getFormattedName('home')) : '',
                'url' => $this->context->link->getProductLink($product),
            ],
            'geo_t' => match ((string) $this->context->language->iso_code) {
                'fr' => ['cta' => 'Ajouter au panier et commander', 'view' => 'Voir le produit', 'note' => 'Vous terminez la commande vous-même (adresse, paiement).'],
                'it' => ['cta' => 'Aggiungi al carrello e ordina', 'view' => 'Vedi prodotto', 'note' => 'Completi l’ordine Lei stesso (indirizzo, pagamento).'],
                'en' => ['cta' => 'Add to cart and checkout', 'view' => 'View product', 'note' => 'You complete the order yourself (address, payment).'],
                default => ['cta' => 'In den Warenkorb und zur Kasse', 'view' => 'Produkt ansehen', 'note' => 'Adresse und Zahlung schliessen Sie selbst ab.'],
            },
            'geo_action' => $this->context->link->getModuleLink('geoconvado', 'buy', ['id' => $idProduct, 'ipa' => $ipa, 'qty' => $qty], true),
            'geo_token' => Tools::getToken(false),
        ]);
        $this->setTemplate('module:geoconvado/views/templates/front/buy.tpl');
    }

    /** Menge setzen (nicht addieren): Neuladen oder doppelter Klick verdoppelt nichts. */
    private function addToCart(int $idProduct, int $ipa, int $qty): void
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            $cart = new Cart();
            $cart->id_lang = (int) $this->context->language->id;
            $cart->id_currency = (int) $this->context->currency->id;
            $cart->id_shop = (int) $this->context->shop->id;
            $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
            $cart->id_customer = (int) ($this->context->customer->id ?? 0);
            $cart->id_guest = (int) ($this->context->cookie->id_guest ?? 0);
            $cart->secure_key = (string) ($this->context->customer->secure_key ?? '');
            $cart->add();
            $this->context->cart = $cart;
            $this->context->cookie->__set('id_cart', (int) $cart->id);
            $this->context->cookie->write();
        }

        $current = (int) ($cart->getProductQuantity($idProduct, $ipa)['quantity'] ?? 0);
        $ok = true;
        if ($current < $qty) {
            $ok = (bool) $cart->updateQty($qty - $current, $idProduct, $ipa ?: null);
        } elseif ($current > $qty) {
            $ok = (bool) $cart->updateQty($current - $qty, $idProduct, $ipa ?: null, false, 'down');
        }

        $bot = GeoConvado\Service\BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        GeoConvado\Service\Tracker::log((int) $this->context->shop->id, $bot ?? 'agent', 'buy', 'buy/' . $idProduct . '/' . $ipa . '/' . $qty, $idProduct);

        Tools::redirect($ok
            ? $this->context->link->getPageLink('order', true, null, ['utm_source' => 'ai-agent', 'utm_medium' => 'geoconvado'])
            : $this->context->link->getProductLink($idProduct));
    }
}
