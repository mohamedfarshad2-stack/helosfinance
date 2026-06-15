<?php

namespace App\Domains\Manufacturing\Services;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\MaterialComponent;
use App\Domains\Shared\Models\ProductionWorkStep;
use App\Domains\Shared\Models\Sku;
use ZipArchive;

class SkuRecipeTemplateExportService
{
    public function export(Business $business, string $path): string
    {
        $skus = Sku::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->orderBy('code')
            ->pluck('code')
            ->values()
            ->all();

        $materials = MaterialComponent::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        $workSteps = ProductionWorkStep::query()
            ->where('business_id', $business->id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        $recipeRows = [
            ['sku_code', 'line_type', 'component_name', 'quantity_per_unit', 'unit_cost', 'purchase_unit', 'purchase_unit_cost', 'units_per_purchase_unit', 'waste_percent', 'consumption_unit', 'active', 'note'],
            [$skus[0] ?? '', 'raw_material', $materials[0] ?? '', 1, '', 'sheet', 1200, 12, 5, 'piece', 'yes', 'Select values from dropdowns. Leave unit_cost blank for sheet/yield materials.'],
            [$skus[0] ?? '', 'labour', $workSteps[0] ?? '', 1, '', '', '', '', '', '', 'yes', 'Select an approved work step. Leave unit_cost blank to use its saved rate.'],
        ];

        $lists = [
            'line_types' => ['raw_material', 'labour'],
            'materials' => $materials,
            'work_steps' => $workSteps,
            'active' => ['yes', 'no'],
            'skus' => $skus,
        ];

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('docProps/core.xml', $this->coreProperties());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($lists));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->recipeSheetXml($recipeRows));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->listsSheetXml($lists));
        $zip->close();

        return $path;
    }

    private function recipeSheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'
            .'<col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/>'
            .'<col min="3" max="3" width="26" customWidth="1"/><col min="4" max="10" width="18" customWidth="1"/>'
            .'<col min="11" max="11" width="10" customWidth="1"/><col min="12" max="12" width="52" customWidth="1"/>'
            .'</cols><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';

            foreach ($row as $columnIndex => $value) {
                $xml .= $this->cell($columnIndex + 1, $rowIndex + 1, $value, $rowIndex === 0);
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>'
            .'<dataValidations count="4">'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose a SKU" error="Select an existing SKU code from the dropdown." sqref="A2:A500"><formula1>SKUCodes</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose a line type" error="Select raw_material or labour." sqref="B2:B500"><formula1>LineTypes</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose from approved names" error="Select a material component for raw_material or a labour work step for labour." sqref="C2:C500"><formula1>IF($B2=&quot;raw_material&quot;,MaterialNames,WorkStepNames)</formula1></dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose active" error="Select yes or no." sqref="K2:K500"><formula1>ActiveValues</formula1></dataValidation>'
            .'</dataValidations>'
            .'</worksheet>';

        return $xml;
    }

    private function listsSheetXml(array $lists): string
    {
        $max = max(
            count($lists['line_types']),
            count($lists['materials']),
            count($lists['work_steps']),
            count($lists['active']),
            count($lists['skus']),
        ) + 1;

        $headers = ['Line types', 'Material components', 'Labour work steps', 'Active', 'SKU codes'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        for ($row = 1; $row <= $max; $row++) {
            $xml .= '<row r="'.$row.'">';
            $values = $row === 1
                ? $headers
                : [
                    $lists['line_types'][$row - 2] ?? '',
                    $lists['materials'][$row - 2] ?? '',
                    $lists['work_steps'][$row - 2] ?? '',
                    $lists['active'][$row - 2] ?? '',
                    $lists['skus'][$row - 2] ?? '',
                ];

            foreach ($values as $columnIndex => $value) {
                $xml .= $this->cell($columnIndex + 1, $row, $value, $row === 1);
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function workbookXml(array $lists): string
    {
        $materialEnd = max(count($lists['materials']) + 1, 2);
        $workStepEnd = max(count($lists['work_steps']) + 1, 2);
        $skuEnd = max(count($lists['skus']) + 1, 2);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Recipe Upload" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets>'
            .'<definedNames>'
            .'<definedName name="LineTypes">Lists!$A$2:$A$3</definedName>'
            .'<definedName name="MaterialNames">Lists!$B$2:$B$'.$materialEnd.'</definedName>'
            .'<definedName name="WorkStepNames">Lists!$C$2:$C$'.$workStepEnd.'</definedName>'
            .'<definedName name="ActiveValues">Lists!$D$2:$D$3</definedName>'
            .'<definedName name="SKUCodes">Lists!$E$2:$E$'.$skuEnd.'</definedName>'
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
            .'<dc:title>HELOS SKU Recipe Template</dc:title><dc:creator>HELOS</dc:creator></cp:coreProperties>';
    }
}
