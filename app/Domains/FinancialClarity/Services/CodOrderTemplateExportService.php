<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\CodOrderSource;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\Sku;
use ZipArchive;

class CodOrderTemplateExportService
{
    /**
     * @param  array<int, string>  $businessOptions
     */
    public function export(Business $selectedBusiness, array $businessOptions, string $path): string
    {
        $businessNames = array_values($businessOptions) ?: [$selectedBusiness->name];
        $skuCodes = Sku::query()
            ->where('business_id', $selectedBusiness->id)
            ->where('active', true)
            ->orderBy('code')
            ->pluck('code')
            ->all();
        $sourceNames = CodOrderSource::query()
            ->where('business_id', $selectedBusiness->id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
        $employeeNames = Employee::query()
            ->where('business_id', $selectedBusiness->id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($skuCodes === []) {
            $skuCodes = [''];
        }
        if ($sourceNames === []) {
            $sourceNames = [''];
        }
        if ($employeeNames === []) {
            $employeeNames = [''];
        }

        $rows = [
            ['business_name', 'tracking_number', 'customer_name', 'customer_phone', 'customer_alt_phone', 'address', 'city', 'district', 'sku_code', 'size', 'sale_amount', 'order_source', 'csr_employee', 'remark', 'delivery_instruction', 'preferred_delivery_at', 'order_date'],
            [$selectedBusiness->name, '', 'Customer One', '0771234567', '', 'No 10, Main Street', 'Colombo', 'Colombo', $skuCodes[0] ?? '', '8', 2500, $sourceNames[0] ?? '', $employeeNames[0] ?? '', 'Call after 6pm', 'Leave near gate', '', today()->toDateString()],
            [$selectedBusiness->name, '', 'Customer Two', '0711234567', '', 'No 25, Temple Road', 'Galle', 'Galle', $skuCodes[0] ?? '', '9', 1800, $sourceNames[0] ?? '', $employeeNames[0] ?? '', '', '', '', today()->toDateString()],
        ];

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('docProps/core.xml', $this->coreProperties());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($businessNames, $skuCodes, $sourceNames, $employeeNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->uploadSheetXml($rows));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->listsSheetXml($businessNames, $skuCodes, $sourceNames, $employeeNames));
        $zip->close();

        return $path;
    }

    private function uploadSheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'
            .'<col min="1" max="1" width="28" customWidth="1"/><col min="2" max="2" width="18" customWidth="1"/>'
            .'<col min="3" max="8" width="22" customWidth="1"/><col min="9" max="11" width="16" customWidth="1"/>'
            .'<col min="12" max="13" width="18" customWidth="1"/><col min="14" max="15" width="38" customWidth="1"/><col min="16" max="17" width="18" customWidth="1"/>'
            .'</cols><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';

            foreach ($row as $columnIndex => $value) {
                $xml .= $this->cell($columnIndex + 1, $rowIndex + 1, $value, $rowIndex === 0);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData>'
            .'<dataValidations count="4">'
            .'<dataValidation type="list" allowBlank="0" showErrorMessage="1" errorTitle="Choose business" error="Select a business name from the dropdown." sqref="A2:A1000"><formula1>BusinessNames</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose SKU" error="Select an existing SKU code or leave blank." sqref="I2:I1000"><formula1>SkuCodes</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose source" error="Select an order source from the dropdown." sqref="L2:L1000"><formula1>OrderSources</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose CSR" error="Select a CSR employee from the dropdown." sqref="M2:M1000"><formula1>CsrEmployees</formula1></dataValidation>'
            .'</dataValidations></worksheet>';
    }

    /**
     * @param  list<string>  $businessNames
     * @param  list<string>  $skuCodes
     * @param  list<string>  $sourceNames
     * @param  list<string>  $employeeNames
     */
    private function listsSheetXml(array $businessNames, array $skuCodes, array $sourceNames, array $employeeNames): string
    {
        $max = max(count($businessNames), count($skuCodes), count($sourceNames), count($employeeNames), 2) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        for ($row = 1; $row <= $max; $row++) {
            $xml .= '<row r="'.$row.'">';
            $values = $row === 1
                ? ['Business names', 'SKU codes', 'Order sources', 'CSR employees']
                : [
                    $businessNames[$row - 2] ?? '',
                    $skuCodes[$row - 2] ?? '',
                    $sourceNames[$row - 2] ?? '',
                    $employeeNames[$row - 2] ?? '',
                ];

            foreach ($values as $columnIndex => $value) {
                $xml .= $this->cell($columnIndex + 1, $row, $value, $row === 1);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function workbookXml(array $businessNames, array $skuCodes, array $sourceNames, array $employeeNames): string
    {
        $businessEnd = max(count($businessNames) + 1, 2);
        $skuEnd = max(count($skuCodes) + 1, 2);
        $sourceEnd = max(count($sourceNames) + 1, 2);
        $employeeEnd = max(count($employeeNames) + 1, 2);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="COD Orders" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets>'
            .'<definedNames>'
            .'<definedName name="BusinessNames">Lists!$A$2:$A$'.$businessEnd.'</definedName>'
            .'<definedName name="SkuCodes">Lists!$B$2:$B$'.$skuEnd.'</definedName>'
            .'<definedName name="OrderSources">Lists!$C$2:$C$'.$sourceEnd.'</definedName>'
            .'<definedName name="CsrEmployees">Lists!$D$2:$D$'.$employeeEnd.'</definedName>'
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
            .'<dc:title>HELOS COD Order Upload Template</dc:title><dc:creator>HELOS</dc:creator></cp:coreProperties>';
    }
}
