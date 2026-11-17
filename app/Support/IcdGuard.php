<?php

namespace App\Support;

/**
 * Pengaman: teks AI tidak boleh memuat kode ICD/INA-CBG. Koding dilakukan koder di E-Klaim.
 * Pengecualian satu-satunya: saran kode (CodeSuggestion, SynthesizeFolderAnalysis::saveCodes) yang sengaja
 * TIDAK melewati strip(); jalur itu memakai cek format CodeSuggestion::validFormat. Jalur lain tetap dijaga.
 */
class IcdGuard
{
    /** Buang pola yang menyerupai kode ICD-10 (mis. "(E11.9)") atau label "ICD-10: E11" bila model tetap menuliskannya. */
    public static function strip(string $text): string
    {
        $text = preg_replace('/\s*[\(\[]\s*[A-TV-Z]\d{2}(?:\.\d{1,2})?\s*[\)\]]/u', '', $text);

        return trim(preg_replace('/\b(?:ICD-?10|ICD-?9(?:-CM)?)\s*:?\s*[A-Z]?\d{1,2}(?:\.\d{1,2})?\b/iu', '', $text));
    }
}
