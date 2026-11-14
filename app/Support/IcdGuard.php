<?php

namespace App\Support;

/** Pengaman: Casemix AI tidak boleh menghasilkan kode ICD/INA-CBG. Koding dilakukan koder di E-Klaim. */
class IcdGuard
{
    /** Buang pola yang menyerupai kode ICD-10 (mis. "(E11.9)") atau label "ICD-10: E11" bila model tetap menuliskannya. */
    public static function strip(string $text): string
    {
        $text = preg_replace('/\s*[\(\[]\s*[A-TV-Z]\d{2}(?:\.\d{1,2})?\s*[\)\]]/u', '', $text);

        return trim(preg_replace('/\b(?:ICD-?10|ICD-?9(?:-CM)?)\s*:?\s*[A-Z]?\d{1,2}(?:\.\d{1,2})?\b/iu', '', $text));
    }
}
