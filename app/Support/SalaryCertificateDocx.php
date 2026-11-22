<?php

namespace App\Support;

/**
 * Creates a Word DOCX from KAU's exact supplied DOCX parts.
 * A stored ZIP implementation avoids requiring PHP's optional ZipArchive extension.
 * No external process and no database write are used.
 */
final class SalaryCertificateDocx
{
    public function build(array $certificates): string
    {
        if (!$certificates || count($certificates) > 500) {
            throw new \DomainException('Select between 1 and 500 certificates.');
        }
        $directory = resource_path('reports/salary-certificate-template');
        $document = file_get_contents($directory.'/word/document.xml');
        if (!is_string($document)) {
            throw new \DomainException('Official Salary Certificate DOCX template is missing.');
        }
        $bodyStart = strpos($document, '<w:body>');
        $sectionStart = strrpos($document, '<w:sectPr ');
        $bodyEnd = strrpos($document, '</w:body>');
        if ($bodyStart === false || $sectionStart === false || $bodyEnd === false) {
            throw new \DomainException('Certificate template layout is invalid.');
        }
        $prefix = substr($document, 0, $bodyStart + strlen('<w:body>'));
        $singlePage = substr($document, $bodyStart + strlen('<w:body>'),
            $sectionStart - ($bodyStart + strlen('<w:body>')));
        $sectionAndEnd = substr($document, $sectionStart);

        $pages = [];
        foreach ($certificates as $certificate) {
            $values = [
                '{{NAME}}' => $certificate['name'],
                '{{DESIGNATION}}' => $certificate['designation'],
                '{{PAY_SCALE}}' => $certificate['pay_scale'],
                '{{APPROVED_BASIC}}' => $certificate['approved_basic'],
                '{{SERVICE_ID}}' => $certificate['employee_code'],
                '{{BANK_ACCOUNT}}' => $certificate['bank_account'],
                '{{MONTH}}' => $certificate['month'],
                '{{FIXATION}}' => (string)$certificate['fixation'],
                '{{PREFIX}}' => $certificate['prefix'],
                '{{DEPARTMENT}}' => $certificate['department'],
                '{{POSSESSIVE}}' => $certificate['possessive'],
                '{{OBJECT_PRONOUN}}' => $certificate['object_pronoun'],
                '{{GRADE_RANGE}}' => $certificate['grade_range'],
                '{{PAY_SCALE_YEAR}}' => (string)$certificate['pay_scale_year'],
                '{{IN_WORDS}}' => $certificate['in_words'],
            ];
            foreach ($certificate['cells'] as $row => $columns) {
                foreach (['current', 'arrear', 'total'] as $column) {
                    $values['{{R'.$row.'_'.strtoupper($column).'}}'] = number_format($columns[$column] / 100, 2, '.', ',');
                }
            }
            $safe = [];
            foreach ($values as $key => $value) {
                $safe[$key] = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
            $page = $this->renderSummaryTable($singlePage, $certificate);
            // Original template contains three empty spacing paragraphs before
            // the signature block. For longer Summary tables remove only those
            // blank paragraphs so each employee's signatories fit on one A4 page.
            if (count($certificate['allowance_rows']) + count($certificate['deduction_rows']) > 13) {
                $courtesy = strpos($page, 'I wish ');
                if ($courtesy !== false) {
                    $after = strpos($page, '</w:p>', $courtesy);
                    if ($after !== false) {
                        $after += strlen('</w:p>');
                        for ($i = 0; $i < 3; $i++) {
                            if (!preg_match('/^<w:p(?:\s[^>]*)?>.*?<\/w:p>/s', substr($page, $after), $match)) break;
                            if (preg_match('/<w:t(?:\s[^>]*)?>[^<]+<\/w:t>/', $match[0])) break;
                            $page = substr_replace($page, '', $after, strlen($match[0]));
                        }
                    }
                }
            }
            $page = strtr($page, $safe);
            if (preg_match('/\{\{[A-Z0-9_]+\}\}/', $page)) {
                throw new \DomainException('Certificate template has unresolved placeholder.');
            }
            // Each employee begins on their own A4 sheet. A pageBreakBefore paragraph
            // avoids changing any content/paragraph/table style from the original DOCX.
            if ($pages) {
                $pages[] = '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';
            }
            $pages[] = $page;
        }
        $outputDocument = $prefix.implode('', $pages).$sectionAndEnd;
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $content = ($name === 'word/document.xml') ? $outputDocument : file_get_contents($file->getPathname());
            if (!is_string($content)) throw new \DomainException('Cannot read template file '.$name);
            $entries[$name] = $content;
        }
        ksort($entries);
        return $this->zip($entries);
    }

    /** Replace only the fixed component rows, preserving the original DOCX table styles. */
    private function renderSummaryTable(string $page, array $certificate): string
    {
        preg_match_all('/<w:tbl(?:\s[^>]*)?>.*?<\/w:tbl>/s', $page, $tables);
        foreach ($tables[0] as $table) {
            if (!str_contains($table, '{{R2_CURRENT}}')) continue;
            preg_match_all('/<w:tr(?:\s[^>]*)?>.*?<\/w:tr>/s', $table, $matches);
            $rows = $matches[0];
            if (count($rows) !== 20) throw new \DomainException('Original certificate table changed');
            $allowances = $certificate['allowance_rows'] ?? [];
            $deductions = $certificate['deduction_rows'] ?? [];
            if (!$allowances || !$deductions) throw new \DomainException('Salary Summary rows unavailable');
            $replacement = array_slice($rows, 0, 2);
            foreach ($allowances as $entry) {
                $replacement[] = $this->fillFinancialRow($rows[2], $entry);
            }
            $replacement[] = $rows[9]; // Gross Salary / Payable
            $replacement[] = $rows[10]; // Deductions / Recoveries
            $replacement[] = $rows[11]; // same column header
            foreach ($deductions as $entry) {
                $replacement[] = $this->fillFinancialRow($rows[12], $entry);
            }
            $replacement[] = $rows[18]; // Total Deductions
            $replacement[] = $rows[19]; // Net Payable
            $newTable = str_replace(implode('', $rows), implode('', $replacement), $table);
            if ($newTable === $table) throw new \DomainException('Could not expand original financial table');
            return str_replace($table, $newTable, $page);
        }
        throw new \DomainException('Original certificate financial table missing');
    }

    private function fillFinancialRow(string $template, array $entry): string
    {
        preg_match_all('/<w:tc(?:\s[^>]*)?>.*?<\/w:tc>/s', $template, $matches);
        $cells = $matches[0];
        if (count($cells) !== 5) throw new \DomainException('Unexpected certificate columns');
        $values = [
            (string) $entry['sl'],
            (string) $entry['particulars'],
            number_format($entry['current'] / 100, 2, '.', ','),
            number_format($entry['arrear'] / 100, 2, '.', ','),
            number_format($entry['total'] / 100, 2, '.', ','),
        ];
        $updated = [];
        foreach ($cells as $i => $cell) {
            $first = true;
            $updated[] = preg_replace_callback('/(<w:t(?:\s[^>]*)?>).*?(<\/w:t>)/s',
                static function ($match) use (&$first, $values, $i) {
                    $value = $first ? htmlspecialchars($values[$i], ENT_XML1 | ENT_QUOTES, 'UTF-8') : '';
                    $first = false;
                    return $match[1].$value.$match[2];
                }, $cell);
            if ($first) throw new \DomainException('Certificate template cell has no text element');
        }
        return str_replace(implode('', $cells), implode('', $updated), $template);
    }

    private function zip(array $entries): string
    {
        $output = '';
        $central = '';
        $offset = 0;
        $count = 0;
        foreach ($entries as $name => $data) {
            $path = (string)$name;
            $length = strlen($data);
            $crc = crc32($data);
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc,
                $length, $length, strlen($path), 0);
            $output .= $header.$path.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0,
                $crc, $length, $length, strlen($path), 0, 0, 0, 0, 0, $offset).$path;
            $offset += strlen($header) + strlen($path) + $length;
            $count++;
        }
        $output .= $central;
        $output .= pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central),
            $offset, 0);
        return $output;
    }
}
