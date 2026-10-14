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

use GeoConvado\Service\AiReferral;
use GeoConvado\Service\Attribution;
use GeoConvado\Service\BotDetector;
use GeoConvado\Service\OfferRules;
use GeoConvado\Service\ProductOffer;
use GeoConvado\Service\Tracker;

class GeoConvadoBuyModuleFrontController extends ModuleFrontController
{
    /**
     * GET zeigt nur eine Bestätigungsseite (Bots, Link-Scanner und Vorschauen erzeugen keinen Warenkorb).
     * Erst der Klick des Kunden (POST mit Token) legt genau die gezeigte Variante in den Warenkorb.
     * Eine ungültige Variante wird nie durch eine andere ersetzt: Der Kunde wählt sie sichtbar aus.
     */
    public function initContent(): void
    {
        parent::initContent();
        header('X-Robots-Tag: noindex, nofollow');

        $idProduct = (int) Tools::getValue('id');
        $qty = max(1, min(20, (int) Tools::getValue('qty', 1)));
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $product = ProductOffer::load($idProduct, $idLang, $idShop);
        if (!$product) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }
        $variants = ProductOffer::variants($product, $idLang, $idShop);
        $hasVariants = (int) $variants[0]['combination'] > 0;
        $raw = Tools::getValue('ipa', null);
        $ipa = $raw === null || $raw === '' ? null : (int) $raw;
        $known = in_array((int) $ipa, array_map(static fn (array $v): int => (int) $v['combination'], $variants), true);
        $error = '';

        if (Tools::isSubmit('geo_confirm')) {
            if (Tools::getValue('token') !== Tools::getToken(false)) {
                $error = 'token';
            } else {
                $r = OfferRules::choose($variants, $hasVariants ? $ipa : null, $qty);
                if ($r['ok']) {
                    $this->addToCart($idProduct, (int) $r['variant']['combination'], $qty);

                    return;
                }
                $error = (string) $r['code'];
            }
        } elseif ($hasVariants && $ipa !== null && !$known) {
            $error = 'combination_invalid';
        } elseif ($hasVariants && $known) {
            foreach ($variants as $v) {
                if ((int) $v['combination'] === (int) $ipa && !$v['orderable']) {
                    $error = 'not_orderable';
                }
            }
        }

        // Vorauswahl nur bei gültiger Angabe; ohne Angabe die Standardvariante, sichtbar und änderbar.
        $selected = $hasVariants ? ($known ? (int) $ipa : ($ipa === null ? OfferRules::summarize($variants)['default_combination'] : 0)) : 0;
        foreach ($variants as $v) {
            if ($hasVariants && (int) $v['combination'] === $selected && !$v['orderable']) {
                $selected = 0;
            }
        }

        $bot = BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        Tracker::log($idShop, $bot ?? 'agent', 'bview', 'view/' . $idProduct . '/' . $selected . '/' . $qty, $idProduct);

        $locale = Tools::getContextLocale($this->context);
        $cur = (string) $this->context->currency->iso_code;
        $options = [];
        $price = '';
        foreach ($variants as $v) {
            if ((int) $v['combination'] === $selected) {
                $price = $locale->formatPrice((float) $v['price'] * $qty, $cur);
            }
            if ($hasVariants) {
                $options[] = ['id' => (int) $v['combination'], 'label' => implode(', ', $v['options']) . ' – ' . $locale->formatPrice((float) $v['price'], $cur),
                    'orderable' => (bool) $v['orderable'], 'selected' => (int) $v['combination'] === $selected];
            }
        }
        $cover = Product::getCover($idProduct);
        $this->context->smarty->assign([
            'geo_p' => [
                'name' => (string) $product->name,
                'qty' => $qty,
                'price' => $price,
                'img' => !empty($cover['id_image']) ? $this->context->link->getImageLink((string) $product->link_rewrite, $idProduct . '-' . (int) $cover['id_image'], ImageType::getFormattedName('home')) : '',
                'url' => $this->context->link->getProductLink($product),
            ],
            'geo_variants' => $options,
            'geo_ipa' => $selected,
            'geo_error' => $error === '' ? '' : self::message($error, (string) $this->context->language->iso_code),
            'geo_t' => self::texts((string) $this->context->language->iso_code),
            'geo_action' => $this->context->link->getModuleLink('geoconvado', 'buy', ['id' => $idProduct, 'qty' => $qty], true),
            'geo_token' => Tools::getToken(false),
        ]);
        $this->setTemplate('module:geoconvado/views/templates/front/buy.tpl');
    }

    /** @return array<string, string> */
    private static function texts(string $iso): array
    {
        return match ($iso) {
            'fr' => ['cta' => 'Ajouter au panier et commander', 'view' => 'Voir le produit', 'note' => 'Vous terminez la commande vous-même (adresse, paiement).', 'choose' => 'Variante', 'unavailable' => 'indisponible'],
            'it' => ['cta' => 'Aggiungi al carrello e ordina', 'view' => 'Vedi prodotto', 'note' => 'Completi l’ordine Lei stesso (indirizzo, pagamento).', 'choose' => 'Variante', 'unavailable' => 'non disponibile'],
            'en' => ['cta' => 'Add to cart and checkout', 'view' => 'View product', 'note' => 'You complete the order yourself (address, payment).', 'choose' => 'Variant', 'unavailable' => 'unavailable'],
            default => ['cta' => 'In den Warenkorb und zur Kasse', 'view' => 'Produkt ansehen', 'note' => 'Adresse und Zahlung schliessen Sie selbst ab.', 'choose' => 'Variante', 'unavailable' => 'nicht lieferbar'],
        };
    }

    private static function message(string $code, string $iso): string
    {
        $m = [
            'de' => ['combination_required' => 'Bitte wählen Sie eine Variante.', 'combination_invalid' => 'Die verlinkte Variante gibt es nicht. Bitte wählen Sie eine Variante.',
                'not_orderable' => 'Diese Variante ist zurzeit nicht lieferbar.', 'below_minimum' => 'Die Mindestmenge ist nicht erreicht.',
                'insufficient_stock' => 'Nicht genügend an Lager für diese Menge.', 'token' => 'Die Seite ist abgelaufen. Bitte erneut bestätigen.'],
            'fr' => ['combination_required' => 'Veuillez choisir une variante.', 'combination_invalid' => 'La variante indiquée n’existe pas. Veuillez choisir une variante.',
                'not_orderable' => 'Cette variante n’est pas disponible actuellement.', 'below_minimum' => 'La quantité minimale n’est pas atteinte.',
                'insufficient_stock' => 'Stock insuffisant pour cette quantité.', 'token' => 'La page a expiré. Veuillez confirmer à nouveau.'],
            'it' => ['combination_required' => 'Scelga una variante.', 'combination_invalid' => 'La variante indicata non esiste. Scelga una variante.',
                'not_orderable' => 'Questa variante non è disponibile al momento.', 'below_minimum' => 'Quantità minima non raggiunta.',
                'insufficient_stock' => 'Scorte insufficienti per questa quantità.', 'token' => 'La pagina è scaduta. Confermi di nuovo.'],
            'en' => ['combination_required' => 'Please choose a variant.', 'combination_invalid' => 'The linked variant does not exist. Please choose a variant.',
                'not_orderable' => 'This variant is currently unavailable.', 'below_minimum' => 'The minimum quantity is not reached.',
                'insufficient_stock' => 'Not enough stock for this quantity.', 'token' => 'The page has expired. Please confirm again.'],
        ];
        $t = $m[$iso] ?? $m['de'];

        return $t[$code] ?? $t['not_orderable'];
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

        $bot = BotDetector::detect((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        Tracker::log((int) $this->context->shop->id, $bot ?? 'agent', 'buy', 'buy/' . $idProduct . '/' . $ipa . '/' . $qty, $idProduct);
        if ($ok && (int) Configuration::get('GEOCONVADO_ATTRIBUTION')) {
            $src = AiReferral::decode((string) ($this->context->cookie->geo_ai ?? ''), time());
            Attribution::markCart((int) $this->context->shop->id, (int) $cart->id, $bot ?? $src ?? 'agent', Attribution::CHANNEL_AGENT);
        }

        Tools::redirect($ok
            ? $this->context->link->getPageLink('order', true, null, ['utm_source' => 'ai-agent', 'utm_medium' => 'geoconvado'])
            : $this->context->link->getProductLink($idProduct));
    }
}
