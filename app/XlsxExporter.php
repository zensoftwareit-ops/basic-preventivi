<?php

declare(strict_types=1);

final class XlsxExporter
{
    private const HEADERS = [
        'ID', 'Numero pratica', 'Data richiesta', 'Ora', 'Cliente', 'Referente cliente',
        'Telefono', 'Email', 'Canale', 'Servizio', 'Descrizione', 'Ricevuto da',
        'Responsabile', 'Priorità', 'Stato', 'Scadenza invio', 'Semaforo', 'Data invio',
        'Valore stimato', 'Probabilità', 'Valore ponderato', 'Esito', 'Note esito',
        'Link esterno', 'Ultimo aggiornamento', 'Creato il', 'Archiviato il',
    ];

    private const WIDTHS = [
        9, 20, 14, 9, 28, 24, 17, 28, 18, 24, 42, 22, 22, 16, 20, 20, 25,
        20, 16, 14, 18, 18, 34, 38, 20, 20, 20,
    ];

    public function download(array $quotes, string $filterSummary = ''): never
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP zip non disponibile: abilitarla in Plesk.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'basic-preventivi-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Impossibile creare il file temporaneo per l\'export.');
        }

        try {
            $this->write($temporaryPath, $quotes, $filterSummary);
            $filename = 'preventivi-' . date('Ymd-His') . '.xlsx';
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string) filesize($temporaryPath));
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            readfile($temporaryPath);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
        exit;
    }

    public function write(string $path, array $quotes, string $filterSummary = ''): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP zip non disponibile: abilitarla in Plesk.');
        }

        $lastRow = max(4, 3 + count($quotes));
        $lastColumn = $this->columnName(count(self::HEADERS));
        $tableReference = 'A3:' . $lastColumn . $lastRow;
        $sheetXml = $this->sheetXml($quotes, $filterSummary, $lastColumn, $lastRow);

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Impossibile creare il file XLSX.');
        }

        try {
            $this->add($zip, '[Content_Types].xml', $this->contentTypesXml());
            $this->add($zip, '_rels/.rels', $this->rootRelationshipsXml());
            $this->add($zip, 'docProps/app.xml', $this->appPropertiesXml());
            $this->add($zip, 'docProps/core.xml', $this->corePropertiesXml());
            $this->add($zip, 'xl/workbook.xml', $this->workbookXml());
            $this->add($zip, 'xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
            $this->add($zip, 'xl/styles.xml', $this->stylesXml());
            $this->add($zip, 'xl/worksheets/sheet1.xml', $sheetXml);
            $this->add($zip, 'xl/worksheets/_rels/sheet1.xml.rels', $this->sheetRelationshipsXml());
            $this->add($zip, 'xl/tables/table1.xml', $this->tableXml($tableReference));
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Impossibile finalizzare il file XLSX.');
            }
        }
    }

    private function sheetXml(array $quotes, string $filterSummary, string $lastColumn, int $lastRow): string
    {
        $title = 'Esportazione preventivi Basic';
        $metadata = 'Generato il ' . date('d/m/Y H:i');
        if (trim($filterSummary) !== '') {
            $metadata .= ' | ' . trim($filterSummary);
        }

        $rows = [];
        $rows[] = '<row r="1" ht="28" customHeight="1">'
            . $this->textCell('A1', $title, 1) . '</row>';
        $rows[] = '<row r="2" ht="22" customHeight="1">'
            . $this->textCell('A2', $metadata, 2) . '</row>';

        $headerCells = '';
        foreach (self::HEADERS as $index => $header) {
            $headerCells .= $this->textCell($this->columnName($index + 1) . '3', $header, 3);
        }
        $rows[] = '<row r="3" ht="28" customHeight="1">' . $headerCells . '</row>';

        foreach (array_values($quotes) as $index => $quote) {
            $rowNumber = $index + 4;
            $cells = $this->quoteCells($rowNumber, $quote);
            $rows[] = '<row r="' . $rowNumber . '" ht="20" customHeight="1">' . $cells . '</row>';
        }
        if ($quotes === []) {
            $blankCells = '';
            foreach (self::HEADERS as $index => $_header) {
                $blankCells .= $this->textCell($this->columnName($index + 1) . '4', '', 4);
            }
            $rows[] = '<row r="4" ht="20" customHeight="1">' . $blankCells . '</row>';
        }

        $columns = '';
        foreach (self::WIDTHS as $index => $width) {
            $column = $index + 1;
            $columns .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $lastColumn . max(3, $lastRow) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane xSplit="2" ySplit="3" topLeftCell="C4" activePane="bottomRight" state="frozen"/>'
            . '<selection pane="bottomRight" activeCell="C4" sqref="C4"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . $columns . '</cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<mergeCells count="2"><mergeCell ref="A1:' . $lastColumn . '1"/><mergeCell ref="A2:' . $lastColumn . '2"/></mergeCells>'
            . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>'
            . '</worksheet>';
    }

    private function quoteCells(int $row, array $quote): string
    {
        $traffic = match ((string) ($quote['traffic_light'] ?? '')) {
            'NEI TEMPI' => 'Verde - Nei tempi',
            'SCADUTO_24' => 'Giallo - Scaduto da meno di 24 ore',
            'SCADUTO_48' => 'Rosso - Scaduto da oltre 24 ore',
            'CHIUSO' => 'Chiuso',
            default => (string) ($quote['traffic_light'] ?? ''),
        };
        $probability = max(0, min(100, (float) ($quote['probability'] ?? 0))) / 100;
        $values = [
            ['number', (int) ($quote['id'] ?? 0), 9],
            ['text', $quote['practice_code'] ?? '', 4],
            ['date', $quote['request_date'] ?? null, 5],
            ['text', isset($quote['request_time']) ? substr((string) $quote['request_time'], 0, 5) : '', 4],
            ['text', $quote['customer_name'] ?? '', 4],
            ['text', $quote['customer_contact'] ?? '', 4],
            ['text', $quote['phone'] ?? '', 4],
            ['text', $quote['email'] ?? '', 4],
            ['text', $quote['channel_name'] ?? '', 4],
            ['text', $quote['service_name'] ?? '', 4],
            ['text', $quote['request_description'] ?? '', 4],
            ['text', $quote['received_by_name'] ?? '', 4],
            ['text', $quote['responsible_name'] ?? '', 4],
            ['text', $quote['priority_name'] ?? '', 4],
            ['text', $quote['status_name'] ?? '', 4],
            ['datetime', $quote['quote_deadline'] ?? null, 6],
            ['text', $traffic, 4],
            ['datetime', $quote['date_sent'] ?? null, 6],
            ['number', (float) ($quote['estimated_value'] ?? 0), 7],
            ['number', $probability, 8],
            ['number', (float) ($quote['weighted_value'] ?? 0), 7],
            ['text', $quote['outcome_name'] ?? '', 4],
            ['text', $quote['loss_notes'] ?? '', 4],
            ['text', $quote['external_link'] ?? '', 4],
            ['datetime', $quote['last_update_at'] ?? null, 6],
            ['datetime', $quote['created_at'] ?? null, 6],
            ['datetime', $quote['archived_at'] ?? null, 6],
        ];

        $cells = '';
        foreach ($values as $index => [$type, $value, $style]) {
            $reference = $this->columnName($index + 1) . $row;
            $cells .= match ($type) {
                'number' => $this->numberCell($reference, (float) $value, $style),
                'date', 'datetime' => $this->dateCell($reference, $value, $style),
                default => $this->textCell($reference, (string) $value, $style),
            };
        }
        return $cells;
    }

    private function textCell(string $reference, string $value, int $style): string
    {
        $value = $this->cleanText($value);
        if ($value === '') {
            return '<c r="' . $reference . '" s="' . $style . '"/>';
        }
        return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . $this->xml($value) . '</t></is></c>';
    }

    private function numberCell(string $reference, float $value, int $style): string
    {
        $number = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        return '<c r="' . $reference . '" s="' . $style . '" t="n"><v>' . ($number === '' ? '0' : $number) . '</v></c>';
    }

    private function dateCell(string $reference, mixed $value, int $style): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '<c r="' . $reference . '" s="' . $style . '"/>';
        }
        try {
            $date = new DateTimeImmutable((string) $value);
            $serial = gmmktime(
                (int) $date->format('H'),
                (int) $date->format('i'),
                (int) $date->format('s'),
                (int) $date->format('m'),
                (int) $date->format('d'),
                (int) $date->format('Y')
            ) / 86400 + 25569;
            return $this->numberCell($reference, $serial, $style);
        } catch (Throwable) {
            return '<c r="' . $reference . '" s="' . $style . '"/>';
        }
    }

    private function tableXml(string $reference): string
    {
        $columns = '';
        foreach (self::HEADERS as $index => $header) {
            $columns .= '<tableColumn id="' . ($index + 1) . '" name="' . $this->xml($header) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="Preventivi" displayName="Preventivi" ref="' . $reference . '" totalsRowShown="0">'
            . '<autoFilter ref="' . $reference . '"/><tableColumns count="' . count(self::HEADERS) . '">' . $columns . '</tableColumns>'
            . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
            . '</table>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/>'
            . '<numFmt numFmtId="166" formatCode="#,##0.00 [$&#8364;-it-IT]"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Calibri"/></font>'
            . '<font><i/><color rgb="FF34445E"/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Calibri"/></font></fonts>'
            . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF18223B"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2F7DF4"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFDDE4EE"/></left><right style="thin"><color rgb="FFDDE4EE"/></right>'
            . '<top style="thin"><color rgb="FFDDE4EE"/></top><bottom style="thin"><color rgb="FFDDE4EE"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
            . '<xf numFmtId="10" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="right" vertical="top"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normale" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="1" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets><sheet name="Preventivi" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="191029"/></workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function sheetRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>'
            . '</Relationships>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Basic Preventivi</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<Company></Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>1.0</AppVersion>'
            . '</Properties>';
    }

    private function corePropertiesXml(): string
    {
        $time = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Esportazione preventivi</dc:title><dc:creator>Basic Preventivi</dc:creator><cp:lastModifiedBy>Basic Preventivi</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $time . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $time . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function add(ZipArchive $zip, string $path, string $contents): void
    {
        if (!$zip->addFromString($path, $contents)) {
            throw new RuntimeException('Impossibile aggiungere ' . $path . ' al file XLSX.');
        }
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
        return mb_substr($value, 0, 32767);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
