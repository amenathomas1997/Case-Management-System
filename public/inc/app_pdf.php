<?php
declare(strict_types=1);
require_once __DIR__ . '/fpdf/fpdf.php';

// Visual PDFs have a language; the accompanying HTML supplies semantic structure.
// Readers show and announce the document Title (set with SetTitle) rather than the
// file name.
class AppPdf extends FPDF {
    protected function _putcatalog() {
        parent::_putcatalog();
        $this->_put('/Lang (en-US)');
        $this->_put('/ViewerPreferences << /DisplayDocTitle true >>');
    }
}
