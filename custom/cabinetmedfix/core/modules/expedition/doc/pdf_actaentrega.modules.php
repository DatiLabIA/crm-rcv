<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/**
 * \file    custom/cabinetmedfix/core/modules/expedition/doc/pdf_actaentrega.modules.php
 * \ingroup cabinetmedfix
 * \brief   PDF generator for acta de entrega de medicamentos (shipment/expedition)
 *
 * Generates a clean delivery certificate for medication shipments showing:
 * - Patient information
 * - Medication lines with serial numbers
 * - Signature sections for patient and responsible staff
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/expedition/modules_expedition.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

/**
 * PDF generator for the medication delivery certificate (Acta de Entrega).
 *
 * Layout:
 *   - Header: foundation name + title
 *   - Info block: patient, ref, date, responsible
 *   - Lines table: product, serial/lot, quantity
 *   - Signatures: patient + foundation representative
 */
class pdf_actaentrega extends ModelePdfExpedition
{
	/** @var DoliDB */
	public $db;

	/** @var string Internal model name */
	public $name;

	/** @var string Short description */
	public $description;

	/** @var int Save generated filename as main doc */
	public $update_main_doc_field = 1;

	/** @var string Document type: 'pdf' */
	public $type = 'pdf';

	/** @var string Dolibarr version */
	public $version = 'dolibarr';

	/** @var array Page format [width, height] */
	public $format;

	/** @var float|int Page width in mm */
	public $page_largeur;

	/** @var float|int Page height in mm */
	public $page_hauteur;

	/** @var float|int Left margin */
	public $marge_gauche;

	/** @var float|int Right margin */
	public $marge_droite;

	/** @var float|int Top margin */
	public $marge_haute;

	/** @var float|int Bottom margin */
	public $marge_basse;

	/** @var Societe Issuing entity (my company) */
	public $emetteur;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database connection
	 */
	public function __construct(DoliDB $db)
	{
		global $langs, $mysoc;

		$this->db = $db;
		$this->name = 'actaentrega';
		$this->description = 'Acta de Entrega de Medicamentos';

		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 15);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 15);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 15);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 15);

		$this->option_logo = 1;
		$this->option_draft_watermark = 0;

		if ($mysoc !== null) {
			$this->emetteur = $mysoc;
			if (empty($this->emetteur->country_code)) {
				$this->emetteur->country_code = substr($langs->defaultlang, -2);
			}
		}
	}

	/**
	 * Return description for the template selection UI.
	 *
	 * @param  Translate $langs Language object
	 * @return string           HTML description
	 */
	public function info($langs)
	{
		return $this->description;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Build the PDF onto disk.
	 *
	 * @param  Expedition $object          Expedition/shipment object
	 * @param  Translate  $outputlangs     Output language object
	 * @param  string     $srctemplatepath Not used for PHP generators
	 * @param  int        $hidedetails     Hide line details (unused)
	 * @param  int        $hidedesc        Hide description (unused)
	 * @param  int        $hideref         Hide reference (unused)
	 * @return int<-1,1>                   1 on success, <=0 on failure
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf, $langs, $user, $mysoc;

		$object->fetch_thirdparty();

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'companies', 'products', 'sendings', 'productbatch'));

		// --- Output directory & file path -----------------------------------------
		if (empty($conf->expedition->dir_output)) {
			$this->error = 'conf->expedition->dir_output not defined';
			return -1;
		}

		if ($object->specimen) {
			$dir = $conf->expedition->dir_output . '/sending';
			$file = $dir . '/SPECIMEN_actaentrega.pdf';
		} else {
			$expref = dol_sanitizeFileName($object->ref);
			$dir = $conf->expedition->dir_output . '/sending/' . $expref;
			$file = $dir . '/' . $expref . '_actaentrega.pdf';
		}

		if (!file_exists($dir)) {
			if (dol_mkdir($dir) < 0) {
				$this->error = $langs->transnoentities('ErrorCanNotCreateDir', $dir);
				return -1;
			}
		}

		// --- Create PDF -----------------------------------------------------------
		$pdf = pdf_getInstance($this->format);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 45); // leave room for signatures
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->Open();

		$defaultFontSize = pdf_getPDFFontSize($outputlangs);
		$font = pdf_getPDFFont($outputlangs);

		$pdf->AddPage();
		$pageWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite; // usable width

		$curY = $this->marge_haute;

		// =========================================================================
		// HEADER BLOCK
		// =========================================================================
		// Foundation logo (top-right) if available
		$logo = $conf->mycompany->dir_output . '/' . getDolGlobalString('MAIN_INFO_SOCIETE_LOGO');
		if (getDolGlobalString('MAIN_INFO_SOCIETE_LOGO') && file_exists($logo)) {
			$pdf->Image($logo, $this->marge_gauche + $pageWidth - 40, $curY, 40, 20, '', '', '', false, 300, '', false, false, 0, false, false, false);
		}

		// Foundation name
		$pdf->SetFont($font, 'B', $defaultFontSize + 3);
		$pdf->SetTextColor(30, 30, 30);
		$pdf->SetXY($this->marge_gauche, $curY);
		$pdf->Cell($pageWidth - 45, 8, dol_htmlentitiesbr_decode($mysoc->name), 0, 1, 'L');

		// Title
		$pdf->SetFont($font, 'B', $defaultFontSize + 5);
		$pdf->SetTextColor(10, 80, 160);
		$pdf->SetXY($this->marge_gauche, $curY + 9);
		$pdf->Cell($pageWidth, 9, 'ACTA DE ENTREGA DE MEDICAMENTOS', 0, 1, 'C');

		$curY = $curY + 22;

		// Horizontal rule under header
		$pdf->SetDrawColor(10, 80, 160);
		$pdf->SetLineWidth(0.8);
		$pdf->Line($this->marge_gauche, $curY, $this->marge_gauche + $pageWidth, $curY);
		$pdf->SetLineWidth(0.2);
		$pdf->SetDrawColor(128, 128, 128);

		$curY += 5;

		// =========================================================================
		// INFO BLOCK: patient | document info
		// =========================================================================
		$colLeft = $this->marge_gauche;
		$colRight = $this->marge_gauche + $pageWidth / 2;
		$colW = $pageWidth / 2 - 3;

		$pdf->SetFont($font, 'B', $defaultFontSize);
		$pdf->SetTextColor(30, 30, 30);

		// Patient name
		$patientName = !empty($object->thirdparty) ? $object->thirdparty->getFullName($outputlangs) : '';
		if (empty($patientName) && !empty($object->thirdparty)) {
			$patientName = $object->thirdparty->name;
		}

		$pdf->SetXY($colLeft, $curY);
		$pdf->Cell(25, 6, 'Paciente:', 0, 0, 'L');
		$pdf->SetFont($font, '', $defaultFontSize);
		$pdf->Cell($colW - 25, 6, dol_trunc($patientName, 50), 0, 0, 'L');

		// Document ref (right column)
		$pdf->SetFont($font, 'B', $defaultFontSize);
		$pdf->SetXY($colRight, $curY);
		$pdf->Cell(20, 6, 'N\xc2\xba:', 0, 0, 'L');
		$pdf->SetFont($font, '', $defaultFontSize);
		$pdf->Cell($colW - 20, 6, $object->ref, 0, 1, 'L');

		$curY += 7;

		// Patient ID / document number if available
		$patientId = '';
		if (!empty($object->thirdparty->name_alias)) {
			$patientId = $object->thirdparty->name_alias;
		}

		$pdf->SetFont($font, 'B', $defaultFontSize);
		$pdf->SetXY($colLeft, $curY);
		$pdf->Cell(25, 6, 'Identificaci\xc3\xb3n:', 0, 0, 'L');
		$pdf->SetFont($font, '', $defaultFontSize);
		$pdf->Cell($colW - 25, 6, $patientId, 0, 0, 'L');

		// Delivery date
		$deliveryDate = !empty($object->date_delivery) ? $object->date_delivery : (!empty($object->date_valid) ? $object->date_valid : dol_now());
		$pdf->SetFont($font, 'B', $defaultFontSize);
		$pdf->SetXY($colRight, $curY);
		$pdf->Cell(30, 6, 'Fecha entrega:', 0, 0, 'L');
		$pdf->SetFont($font, '', $defaultFontSize);
		$pdf->Cell($colW - 30, 6, dol_print_date($deliveryDate, 'day', 'tzserver', $outputlangs), 0, 1, 'L');

		$curY += 7;

		// Responsible user
		$responsibleName = $user->getFullName($outputlangs);
		if (empty($responsibleName)) {
			$responsibleName = $user->login;
		}

		$pdf->SetFont($font, 'B', $defaultFontSize);
		$pdf->SetXY($colLeft, $curY);
		$pdf->Cell(25, 6, 'Almac\xc3\xa9n:', 0, 0, 'L');
		$pdf->SetFont($font, '', $defaultFontSize);
		$firstWarehouse = '';
		if (!empty($object->lines) && !empty($object->lines[0]->entrepot_id)) {
			require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';
			$warehouse = new Entrepot($this->db);
			if ($warehouse->fetch($object->lines[0]->entrepot_id) > 0) {
				$firstWarehouse = $warehouse->label;
			}
		}
		$pdf->Cell($colW - 25, 6, $firstWarehouse, 0, 0, 'L');

		$pdf->SetFont($font, 'B', $defaultFontSize);
		$pdf->SetXY($colRight, $curY);
		$pdf->Cell(30, 6, 'Entregado por:', 0, 0, 'L');
		$pdf->SetFont($font, '', $defaultFontSize);
		$pdf->Cell($colW - 30, 6, $responsibleName, 0, 1, 'L');

		$curY += 10;

		// =========================================================================
		// LINES TABLE
		// =========================================================================
		// Column widths: # | Medicamento | Serial/Lote | Cantidad
		$colNumW = 8;
		$colSerialW = 52;
		$colQtyW = 22;
		$colProductW = $pageWidth - $colNumW - $colSerialW - $colQtyW;

		// Table header row
		$pdf->SetFillColor(10, 80, 160);
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont($font, 'B', $defaultFontSize - 1);
		$lineHeight = 7;

		$pdf->SetXY($this->marge_gauche, $curY);
		$pdf->Cell($colNumW, $lineHeight, '#', 1, 0, 'C', true);
		$pdf->Cell($colProductW, $lineHeight, 'Medicamento', 1, 0, 'L', true);
		$pdf->Cell($colSerialW, $lineHeight, 'Serial / Lote', 1, 0, 'C', true);
		$pdf->Cell($colQtyW, $lineHeight, 'Cant.', 1, 1, 'C', true);

		$curY += $lineHeight;

		// Table rows
		$pdf->SetTextColor(30, 30, 30);
		$pdf->SetFont($font, '', $defaultFontSize - 1);
		$rownum = 0;
		$fillRow = false;

		foreach ($object->lines as $line) {
			if (empty($line->fk_product) && empty($line->product_label)) {
				continue;
			}

			$productLabel = !empty($line->product_label) ? $line->product_label : $line->product_ref;
			$qtyShipped = (float) $line->qty_shipped;

			// Collect batch/serial entries for this line
			$batches = array();
			if (!empty($line->detail_batch) && is_array($line->detail_batch)) {
				foreach ($line->detail_batch as $batchEntry) {
					$batches[] = array(
						'serial' => !empty($batchEntry->batch) ? $batchEntry->batch : '',
						'qty' => (float) $batchEntry->qty,
					);
				}
			}

			if (empty($batches)) {
				// No batch detail — use line total
				$batches[] = array('serial' => '', 'qty' => $qtyShipped);
			}

			foreach ($batches as $idx => $batch) {
				$rownum++;
				$fillRow = ($rownum % 2 === 0);
				$pdf->SetFillColor(240, 245, 255);
				$pdf->SetXY($this->marge_gauche, $pdf->GetY());
				$pdf->Cell($colNumW, $lineHeight, $idx === 0 ? $rownum : '', 1, 0, 'C', $fillRow);
				$pdf->Cell($colProductW, $lineHeight, dol_trunc($productLabel, 55), 1, 0, 'L', $fillRow);
				$pdf->Cell($colSerialW, $lineHeight, $batch['serial'], 1, 0, 'C', $fillRow);
				$pdf->Cell($colQtyW, $lineHeight, $batch['qty'] > 0 ? $batch['qty'] : $qtyShipped, 1, 1, 'C', $fillRow);
			}
		}

		$curY = $pdf->GetY() + 5;

		// =========================================================================
		// NOTE (if any)
		// =========================================================================
		if (!empty($object->note_public)) {
			$pdf->SetFont($font, 'B', $defaultFontSize - 1);
			$pdf->SetTextColor(80, 80, 80);
			$pdf->SetXY($this->marge_gauche, $curY);
			$pdf->Cell($pageWidth, 5, 'Observaciones:', 0, 1, 'L');
			$pdf->SetFont($font, '', $defaultFontSize - 1);
			$pdf->SetXY($this->marge_gauche, $pdf->GetY());
			$pdf->MultiCell($pageWidth, 5, dol_htmlentitiesbr_decode($object->note_public), 'LRTB', 'L', false);
			$curY = $pdf->GetY() + 4;
		}

		// =========================================================================
		// SIGNATURES SECTION (anchored near bottom of last page)
		// =========================================================================
		$sigY = $this->page_hauteur - $this->marge_basse - 48;
		// If content already pushed below signatures area, place right after content
		if ($pdf->GetY() + 5 > $sigY) {
			$sigY = $pdf->GetY() + 5;
		}

		// Separator before signatures
		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetLineWidth(0.3);
		$pdf->Line($this->marge_gauche, $sigY - 3, $this->marge_gauche + $pageWidth, $sigY - 3);

		$sigColW = ($pageWidth - 20) / 2;
		$sigColLeftX = $this->marge_gauche;
		$sigColRightX = $this->marge_gauche + $sigColW + 20;

		$pdf->SetFont($font, 'B', $defaultFontSize - 1);
		$pdf->SetTextColor(30, 30, 30);

		// Column titles
		$pdf->SetXY($sigColLeftX, $sigY);
		$pdf->Cell($sigColW, 6, 'FIRMA DEL PACIENTE', 0, 0, 'C');
		$pdf->SetXY($sigColRightX, $sigY);
		$pdf->Cell($sigColW, 6, 'FIRMA DEL RESPONSABLE', 0, 1, 'C');

		$sigY += 7;

		// Signature boxes
		$boxH = 18;
		$pdf->SetDrawColor(160, 160, 160);
		$pdf->SetLineWidth(0.3);
		$pdf->Rect($sigColLeftX, $sigY, $sigColW, $boxH);
		$pdf->Rect($sigColRightX, $sigY, $sigColW, $boxH);

		$sigY += $boxH + 3;

		// Name / document lines
		$pdf->SetFont($font, '', $defaultFontSize - 2);
		$pdf->SetTextColor(80, 80, 80);

		$labelLines = array(
			'Nombre y apellidos: ________________________',
			'N\xc2\xba de documento: ________________________',
			'Fecha: ________________________',
		);
		$respLines = array(
			'Nombre y apellidos: ________________________',
			'Cargo: ________________________',
			'Fecha: ________________________',
		);

		foreach ($labelLines as $i => $line) {
			$pdf->SetXY($sigColLeftX, $sigY + $i * 5);
			$pdf->Cell($sigColW, 5, $line, 0, 0, 'L');
		}
		foreach ($respLines as $i => $line) {
			$pdf->SetXY($sigColRightX, $sigY + $i * 5);
			$pdf->Cell($sigColW, 5, $line, 0, 0, 'L');
		}

		// =========================================================================
		// PAGE FOOTER
		// =========================================================================
		$footerY = $this->page_hauteur - $this->marge_basse + 2;
		$pdf->SetFont($font, 'I', $defaultFontSize - 3);
		$pdf->SetTextColor(150, 150, 150);
		$pdf->SetXY($this->marge_gauche, $footerY);
		$pdf->Cell($pageWidth, 4, $mysoc->name . ' — Documento generado el ' . dol_print_date(dol_now(), 'dayhour', 'tzserver', $outputlangs), 0, 0, 'C');

		// =========================================================================
		// SAVE FILE
		// =========================================================================
		$pdf->Close();

		try {
			$pdf->Output($file, 'F');
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			dol_syslog($this->error, LOG_ERR);
			return -1;
		}

		// Update main_doc field on the object record
		if (!$object->specimen) {
			$result = $object->setDocModel($user, 'actaentrega');
			$object->last_main_doc = basename($file);
			$sql = "UPDATE " . MAIN_DB_PREFIX . "expedition SET last_main_doc = '" . $this->db->escape(basename($file)) . "' WHERE rowid = " . (int) $object->id;
			$this->db->query($sql);
		}

		dolChmod($file);

		$this->result = array('fullpath' => $file);
		return 1;
	}
}
