<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

/** The text of a shared or inline string item: its direct text and formatted runs, never the phonetic hints. */
final class RichText
{
    public static function plainText(\DOMElement $item): string
    {
        $text = '';
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ('t' === $child->localName) {
                $text .= $child->textContent;
            } elseif ('r' === $child->localName) {
                foreach ($child->childNodes as $run) {
                    if ($run instanceof \DOMElement && 't' === $run->localName) {
                        $text .= $run->textContent;
                    }
                }
            }
        }

        return $text;
    }
}
