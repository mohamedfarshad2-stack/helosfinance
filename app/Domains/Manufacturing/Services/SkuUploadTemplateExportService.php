<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\Business;
use ZipArchive;

class SkuUploadTemplateExportService
{
    /**
     * @param  array<int, string>  $businessOptions
     */
    public function export(Business $selectedBusiness, array $businessOptions, string $path): string
    {
        $businessNames = array_values($businessOptions);

        if ($businessNames === []) {
            $businessNames = [$selectedBusiness->name];
        }

        $rows = [
            ['business_name', 'code', 'name', 'expected_sale_price', 'active', 'material_cost', 'packaging_cost', 'labor_rate', 'finishing_cost', 'note'],
            [$selectedBusiness->name, 'SLP-001', 'Black Slipper Size 8', 1200, 'yes', '', '', '', '', 'Costs should normally come from Product Cost Recipes.'],
            [$selectedBusiness->name, 'SLP-002', 'Brown Slipper Size 9', 1350, 'yes', '', '', '', '', 'Leave fallback costs blank if recipe will be added.'],
        ];

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('docProps/core.xml', $this->coreProperties());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($businessNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->uploadSheetXml($rows));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->listsSheetXml($businessNames));
        $zip->close();

        return $path;
    }

    private function uploadSheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'
            .'<col min="1" max="1" width="28" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/>'
            .'<col min="3" max="3" width="30" customWidth="1"/><col min="4" max="9" width="18" customWidth="1"/>'
            .'<col min="10" max="10" width="52" customWidth="1"/>'
            .'</cols><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';

            foreach ($row as $columnIndex => $value) {
                $xml .= $this->cell($columnIndex + 1, $rowIndex + 1, $value, $rowIndex === 0);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData>'
            .'<dataValidations count="2">'
            .'<dataValidation type="list" allowBlank="0" showErrorMessage="1" errorTitle="Choose business" error="Select a business name from the dropdown." sqref="A2:A500"><formula1>BusinessNames</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose active" error="Select yes or no." sqref="E2:E500"><formula1>ActiveValues</formula1></dataValidation>'
            .'</dataValidations></worksheet>';
    }

    /**
     * @param  list<string>  $businessNames
     */
    private function listsSheetXml(array $businessNames): string
    {
        $max = max(count($businessNames), 2) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        for ($row = 1; $row <= $max; $row++) {
            $xml .= '<row r="'.$row.'">';
            $values = $row === 1
                ? ['Business names', 'Active']
                : [
                    $businessNames[$row - 2] ?? '',
                    ['yes', 'no'][$row - 2] ?? '',
                ];

            foreach ($values as $columnIndex => $value) {
                $xml .= $this->cell($columnIndex + 1, $row, $value, $row === 1);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @param  list<string>  $businessNames
     */
    private function workbookXml(array $businessNames): string
    {
        $businessEnd = max(count($businessNames) + 1, 2);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Product Upload" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets>'
            .'<definedNames>'
            .'<definedName name="BusinessNames">Lists!$A$2:$A$'.$businessEnd.'</definedName>'
            .'<definedName name="ActiveValues">Lists!$B$2:$B$3</definedName>'
            .'</definedNames></workbook>';
    }

    private function cell(int $column, int $row, mixed $value, bool $header = false): string
    {
        $ref = $this->columnName($column).$row;
        $style = $header ? ' s="1"' : '';

        if (is_numeric($value) && $value !== '') {
            return '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t>'.e((string) $value).'</t></is></c>';
    }

    private function columnName(int $column): string
    {
        $name = '';

        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)).$name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>HELOS</Application></Properties>';
    }

    private function coreProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>HELOS SKU Upload Template</dc:title><dc:creator>HELOS</dc:creator></cp:coreProperties>';
    }
}
