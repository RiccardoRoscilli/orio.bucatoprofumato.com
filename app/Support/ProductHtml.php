<?php

namespace App\Support;

/**
 * Ripulisce l'HTML delle descrizioni prodotto di bucatoprofumato.com prima di salvarlo.
 *
 * Nell'export la descrizione lunga arriva dentro il contenitore del Page Builder di Magento,
 * con l'HTML vero ancora codificato (&lt;p&gt;...). Si tiene solo la formattazione di base
 * (paragrafi, grassetti, elenchi, titoletti) senza attributi, script, stili o link: il
 * risultato si puo' stampare nella pagina del prodotto cosi' com'e'.
 */
class ProductHtml
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4'];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'template', 'noscript', 'svg'];

    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        // Page Builder: <div data-content-type="html">&lt;p&gt;...&lt;/p&gt;</div>
        if (str_contains($html, '&lt;')) {
            $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('root');
        if (! $root) {
            return null;
        }

        self::sanitize($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        // Paragrafi vuoti e spazi ripetuti
        $out = preg_replace('#<p>(\s|&nbsp;|\xc2\xa0|<br>)*</p>#u', '', $out);
        $out = preg_replace("/[ \t]+/", ' ', $out);
        $out = trim(preg_replace("/\n{2,}/", "\n", $out));

        return trim(strip_tags($out)) === '' ? null : $out;
    }

    private static function sanitize(\DOMNode $node): void
    {
        // Copia: la lista dei figli cambia mentre li si sostituisce
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            self::sanitize($child);

            if (in_array($tag, self::ALLOWED, true)) {
                while ($child->attributes->length) {
                    $child->removeAttribute($child->attributes->item(0)->name);
                }
                continue;
            }

            // Tag non ammesso (div, span, a, img, table...): resta solo il contenuto
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
        }
    }
}
