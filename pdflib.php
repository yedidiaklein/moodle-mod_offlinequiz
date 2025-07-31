<?php
// This file is part of mod_offlinequiz for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Creates the PDF forms for offlinequizzes
 *
 * @package       mod
 * @subpackage    offlinequiz
 * @author        Juergen Zimmer <zimmerj7@univie.ac.at>
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @since         Moodle 2.2+
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/lib/pdflib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/questionbase.php');
require_once($CFG->dirroot . '/mod/offlinequiz/html2text.php');
require_once($CFG->dirroot . '/mod/offlinequiz/documentlib.php');

define('LOGO_MAX_ASPECT_RATIO', 3.714285714);

class offlinequiz_barcodewriter {
    /**
     *
     * @param pdf $pdf
     * @param int $barcode
     * @param int $x
     * @param int $y
     */
    public static function print_barcode($pdf, $barcode, $x, $y) {
        // Print bar code for page.
        $value = substr('000000000000000000000000'.base_convert($barcode,  10,  2), -25);
        $pdf->Rect($x, $y, 0.2, 3.5, 'F');
        $pdf->Rect($x, $y, 0.7, 0.2, 'F');
        $pdf->Rect($x, $y + 3.5, 0.7, 0.2, 'F');
        $x += 0.7;
        for ($i = 0; $i < 25; $i++) {
            if ($value[$i] == '1') {
                $pdf->Rect($x, $y, 0.7, 3.5, 'F');
                $pdf->Rect($x, $y, 1.2, 0.2, 'F');
                $pdf->Rect($x, $y + 3.5, 1.2, 0.2, 'F');
                $x += 1;
            } else {
                $pdf->Rect($x, $y, 0.2, 3.5, 'F');
                $pdf->Rect($x, $y, 0.7, 0.2, 'F');
                $pdf->Rect($x, $y + 3.5, 0.7, 0.2, 'F');
                $x += 0.7;
            }
        }
    }
}

class offlinequiz_pdf extends pdf {
    /**
     * Containing the current page buffer after checkpoint() was called.
     * @var mixed
     */
    private $checkpoint;

    /**
     * Class constructor.
     *
     * @param string $orientation page orientation
     * @param string $unit User measure unit
     * @param mixed $format The format used for pages
     * @param bool $unicode TRUE means that the input text is unicode
     * @param string $encoding Charset encoding
     * @param bool $diskcache if TRUE reduce the RAM memory usage
     * @param bool $pdfa if TRUE set the PDF/A-1b mode
     */
    public function __construct(
        $orientation = 'P',
        $unit = 'mm',
        $format = 'A4',
        $unicode = true,
        $encoding = 'UTF-8',
        $diskcache = false,
        $pdfa = false
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        $this->SetTitle(''); // Set a default title.
    }

    /**
     * This method is used to save the current page status.
     */
    public function checkpoint() {
        $this->checkpoint = $this->getPageBuffer($this->page);
    }

    /**
     * This method is used to restore the page status from a checkpoint.
     */
    public function backtrack() {
        $this->setPageBuffer($this->page, $this->checkpoint);
    }

    /**
     * Check if the current position is overflowing the page.
     *
     * @return bool
     */
    public function is_overflowing() {
        return $this->y > $this->PageBreakTrigger;
    }

    /**
     * Set the title of the document.
     *
     * @param string $newtitle The new title.
     */
    public function set_title($newtitle) {
        $this->SetTitle($newtitle);
    }

}

/**
 * Class for question PDF.
 */
class offlinequiz_question_pdf extends offlinequiz_pdf {
    /**
     * Array of temporary files.
     * @var array
     */
    private $tempfiles = [];

    /**
     * Class constructor.
     *
     * @param string $orientation page orientation
     * @param string $unit User measure unit
     * @param mixed $format The format used for pages
     * @param bool $unicode TRUE means that the input text is unicode
     * @param string $encoding Charset encoding
     * @param bool $diskcache if TRUE reduce the RAM memory usage
     * @param bool $pdfa if TRUE set the PDF/A-1b mode
     */
    public function __construct(
        $orientation = 'P',
        $unit = 'mm',
        $format = 'A4',
        $unicode = true,
        $encoding = 'UTF-8',
        $diskcache = false,
        $pdfa = false
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
    }

    /**
     * (non-PHPdoc)
     * @see TCPDF::Header()
     */
    // @codingStandardsIgnoreLine  This function name is not moodle-standard but I need to overwrite TCPDF
    public function Header() {
        $this->SetFont(offlinequiz_get_pdffont(), 'I', 8);
        // Title.
        $this->Ln(15);
        if (!empty($this->title)) {
            $this->Cell(0, 10, $this->title, 0, 0, 'C');
        }
        $this->Rect(15, 25, 175, 0.3, 'F');
        // Line break.
        $this->Ln(15);
    }

    /**
     * (non-PHPdoc)
     * @see TCPDF::Footer()
     */
    // @codingStandardsIgnoreLine  This function name is not moodle-standard but I need to overwrite TCPDF
    public function Footer() {
        // Position at 2.5 cm from bottom.
        $this->SetY(-25);
        $this->SetFont(offlinequiz_get_pdffont(), 'I', 8);
        // Page number.
        $this->Cell(0, 10, offlinequiz_str_html_pdf(get_string('page')) . ' ' . $this->getAliasNumPage() .
                '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

/**
 * Class for participants PDF.
 */
class offlinequiz_participants_pdf extends offlinequiz_pdf {
    /**
     * The list number.
     * @var int
     */
    public $listno;
    /**
     * The group ID.
     * @var int
     */
    public $groupid = 0;
    /**
     * The group letter.
     * @var string
     */
    public $group;
    /**
     * The offlinequiz object.
     * @var stdClass
     */
    public $offlinequiz;
    /**
     * The form type.
     * @var int
     */
    public $formtype;
    /**
     * The column width.
     * @var float
     */
    public $colwidth;
    /**
     * The user ID.
     * @var int
     */
    public $userid;

    /**
     * Class constructor.
     *
     * @param string $orientation page orientation
     * @param string $unit User measure unit
     * @param mixed $format The format used for pages
     * @param bool $unicode TRUE means that the input text is unicode
     * @param string $encoding Charset encoding
     * @param bool $diskcache if TRUE reduce the RAM memory usage
     * @param bool $pdfa if TRUE set the PDF/A-1b mode
     */
    public function __construct(
        $orientation = 'P',
        $unit = 'mm',
        $format = 'A4',
        $unicode = true,
        $encoding = 'UTF-8',
        $diskcache = false,
        $pdfa = false
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
    }

    /**
     * (non-PHPdoc)
     * @see TCPDF::Header()
     */
    // @codingStandardsIgnoreLine  This function name is not moodle-standard but I need to overwrite TCPDF
    public function Header() {
        global $CFG;

        $offlinequizconfig = get_config('offlinequiz');
        $font = offlinequiz_get_pdffont();
        $letterstr = 'ABCDEF';

        $logourl = trim($offlinequizconfig->logourl);
        if (!empty($logourl)) {
            $aspectratio = $this->get_logo_aspect_ratio($logourl);
            if ($aspectratio < LOGO_MAX_ASPECT_RATIO) {
                $newlength = 54 * $aspectratio / LOGO_MAX_ASPECT_RATIO;
                $this->IMAGE($logourl, 133, 10.8, $newlength, 0);
            } else {
                $this->Image($logourl, 133, 10.8, 54, 0);
            }

        }
        // Print the top left fixation cross.
        $this->Line(11, 12, 14, 12);
        $this->Line(12.5, 10.5, 12.5, 13.5);
        $this->Line(193, 12, 196, 12);
        $this->Line(194.5, 10.5, 194.5, 13.5);
        $this->SetFont($font, 'B', 14);
        $this->SetXY(15,  15);
        $this->Cell(90, 4, offlinequiz_str_html_pdf(get_string('answerform',  'offlinequiz')), 0, 0, 'C');
        $this->Ln(6);
        $this->SetFont($font, '', 10);
        $this->Cell(90, 6, offlinequiz_str_html_pdf(get_string('forautoanalysis',  'offlinequiz')), 0, 1, 'C');
        $this->Ln(2);
        $this->SetFont($font, '', 8);
        $this->Cell(90, 7, ' '.offlinequiz_str_html_pdf(get_string('firstname')).":", 1, 0, 'L');
        $this->Cell(29, 7, ' '.offlinequiz_str_html_pdf(get_string('invigilator',  'offlinequiz')), 0, 1, 'C');
        $this->Cell(90, 7, ' '.offlinequiz_str_html_pdf(get_string('lastname')).":", 1, 1, 'L');
        $this->Cell(90, 7, ' '.offlinequiz_str_html_pdf(get_string('signature',  'offlinequiz')).":", 1, 1, 'L');
        $this->Ln(5);
        $this->Cell(20, 7, offlinequiz_str_html_pdf(get_string('group', 'offlinequiz')).":", 0, 0, 'L');
        $this->SetXY(34.4,  57.4);

        // Print boxes for groups.
        for ($i = 0; $i <= 5; $i++) {
            $this->Cell(6,  3.5,  $letterstr[$i], 0, 0, 'R');
            $this->Cell(0.85,  1, '', 0, 0, 'R');
            $this->Rect($this->GetX(),  $this->GetY(),  3.5,  3.5);
            $this->Cell(2.7,  1, '', 0, 0, 'C');
            if (!empty($this->group) && $letterstr[$i] == $this->group) {
                $this->Image("$CFG->dirroot/mod/offlinequiz/pix/kreuz.gif", $this->GetX() - 2.75,  $this->Gety() + 0.15,  3.15,  0);
            }
        }

        $this->Ln(10);
        $this->MultiCell(115, 3, offlinequiz_str_html_pdf(get_string('instruction1',  'offlinequiz')), 0, 'L');
        $this->Ln(1);
        $this->SetY(78);
        $this->Cell(42, 8, "", 0, 0, 'C');
        $this->Rect($this->GetX(),  $this->GetY(),  3.5,  3.5);
        $this->Cell(3.5, 3.5, "", 0, 1, 'C');
        $this->Ln(1);
        $this->MultiCell(115, 3, offlinequiz_str_html_pdf(get_string('instruction2',  'offlinequiz')), 0, 'L');
        $this->Image("$CFG->dirroot/mod/offlinequiz/pix/kreuz.gif",  57.2,  78.2,  3.15, 0);   // JZ added 0.4 to y value.
        $this->Image("$CFG->dirroot/mod/offlinequiz/pix/ausstreichen.jpg", 56.8,  93,  4.1, 0);  // JZ added 0.4 to y value.
        $this->SetY(93.1);
        $this->Cell(42, 8, "", 0, 0, 'C');
        $this->Cell(3.5, 3.5, '', 1, 1, 'C');
        $this->Ln(1);
        $this->MultiCell(115, 3, offlinequiz_str_html_pdf(get_string('instruction3',  'offlinequiz')), 0, 'L');

        $this->Line(109, 29, 130, 29);                                 // Rectangle for the teachers to sign.
        $this->Line(109, 50, 130, 50);
        $this->Line(109, 29, 109, 50);
        $this->Line(130, 29, 130, 50);

        $this->SetFont($font, 'B', 10);
        $this->SetXY(137, 27);
        $this->Cell($offlinequizconfig->ID_digits * 6.5, 7,
                    offlinequiz_str_html_pdf(get_string('idnumber',  'offlinequiz')), 0, 1, 'C');
        $this->SetXY(137, 34);
        $this->Cell($offlinequizconfig->ID_digits * 6.5, 7, '', 1, 1, 'C');  // Box for ID number.

        for ($i = 1; $i < $offlinequizconfig->ID_digits; $i++) {      // Little lines to separate the digits.
            $this->Line(137 + $i * 6.5, 39, 137 + $i * 6.5, 41);
        }

        $this->SetDrawColor(150);
        $this->Line(137,  47.7,  138 + $offlinequizconfig->ID_digits * 6.5,  47.7);  // Line to sparate 0 from the other.
        $this->SetDrawColor(0);

        // Print boxes for the user ID number.
        $this->SetFont($font, '', 12);
        for ($i = 0; $i < $offlinequizconfig->ID_digits; $i++) {
            $x = 139 + 6.5 * $i;
            for ($j = 0; $j <= 9; $j++) {
                $y = 44 + $j * 6;
                $this->Rect($x, $y, 3.5, 3.5);
            }
        }

        // Print the digits for the user ID number.
        $this->SetFont($font, '', 10);
        for ($y = 0; $y <= 9; $y++) {
            $this->SetXY(134, ($y * 6 + 44));
            $this->Cell(3.5, 3.5, "$y", 0, 1, 'C');
            $this->SetXY(138 + $offlinequizconfig->ID_digits * 6.5, ($y * 6 + 44));
            $this->Cell(3.5, 3.5, "$y", 0, 1, 'C');
        }

        $this->Ln();
    }

    private function get_logo_aspect_ratio($logourl) {
        list($originalwidth, $originalheight) = getimagesize($logourl);
        return $originalwidth / $originalheight;
    }


    /**
     * (non-PHPdoc)
     * @see TCPDF::Footer()
     */
    // @codingStandardsIgnoreLine  This function name is not moodle-standard but I need to overwrite TCPDF
    public function Footer() {
        $letterstr = ' ABCDEF';
        $font = offlinequiz_get_pdffont();

        $this->Line(11, 285, 14, 285);
        $this->Line(12.5, 283.5, 12.5, 286.5);
        $this->Line(193, 285, 196, 285);
        $this->Line(194.5, 283.5, 194.5, 286.5);
        $this->Rect(192, 282.5, 2.5, 2.5, 'F');                // Flip indicator.
        $this->Rect(15, 281, 174, 0.5, 'F');                   // Bold line on bottom.

        // Position at x mm from bottom.
        $this->SetY(-20);
        $this->SetFont($font, 'I', 8);
        $this->Cell(10, 4, $this->formtype, 1, 0, 'C');

        // ID of the offline quiz.
        $this->Cell(15, 4, substr('0000000'.$this->offlinequiz, -7), 1, 0, 'C');

        // Letter for the group.
        $this->Cell(10, 4, $letterstr[$this->groupid], 1, 0, 'C');

        // ID of the user who created the form.
        $this->Cell(15, 4, substr('0000000'.$this->userid, -7), 1, 0, 'C');

        // Name of the offline-quiz.
        $title = $this->title;
        $width = 100;

        while ($this->GetStringWidth($title) > ($width - 1)) {
            $title = mb_substr($title,  0,  mb_strlen($title) - 1);
        }
        $this->Cell($width, 4, $title, 1, 0, 'C');

        $y = $this->GetY();
        $x = $this->GetX();
        // Print bar code for page.
        offlinequiz_barcodewriter::print_barcode($this, $this->PageNo(), $x, $y);

        $this->Rect($x, $y, 0.2, 3.7, 'F');

        // Page number.
        $this->Ln(3);
        $this->SetFont($font, 'I', 8);
        $this->Cell(0, 10, offlinequiz_str_html_pdf(get_string('page') . ' ' . $this->getAliasNumPage() . '/' .
                $this->getAliasNbPages()), 0, 0, 'C');
    }
}

class offlinequiz_answer_pdf extends offlinequiz_pdf {
    /**
     * The group ID.
     * @var int
     */
    public $groupid = 0;
    /**
     * The group letter.
     * @var string
     */
    public $group;
    /**
     * The offlinequiz object.
     * @var stdClass
     */
    public $offlinequiz;
    /**
     * The form type.
     * @var int
     */
    public $formtype;

    /**
     * Class constructor.
     *
     * @param string $orientation page orientation
     * @param string $unit User measure unit
     * @param mixed $format The format used for pages
     * @param bool $unicode TRUE means that the input text is unicode
     * @param string $encoding Charset encoding
     * @param bool $diskcache if TRUE reduce the RAM memory usage
     * @param bool $pdfa if TRUE set the PDF/A-1b mode
     */
    public function __construct(
        $orientation = 'P',
        $unit = 'mm',
        $format = 'A4',
        $unicode = true,
        $encoding = 'UTF-8',
        $diskcache = false,
        $pdfa = false
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
    }
    /**
     * The column width.
     * @var float
     */
    public $colwidth;
    /**
     * The user ID.
     * @var int
     */
    public $userid;
    /**
     * (non-PHPdoc)
     * @see TCPDF::Header()
     */
    // @codingStandardsIgnoreLine  This function name is not moodle-standard but I need to overwrite TCPDF
    public function Header() {
        global $CFG;

        $offlinequizconfig = get_config('offlinequiz');
        $font = offlinequiz_get_pdffont();
        $letterstr = 'ABCDEF';

        $logourl = trim($offlinequizconfig->logourl);
        if (!empty($logourl)) {
            $aspectratio = $this->get_logo_aspect_ratio($logourl);
            if ($aspectratio < LOGO_MAX_ASPECT_RATIO) {
                $newlength = 54 * $aspectratio / LOGO_MAX_ASPECT_RATIO;
                $this->IMAGE($logourl, 133, 10.8, $newlength, 0);
            } else {
                $this->Image($logourl, 133, 10.8, 54, 0);
            }

        }
        // Print the top left fixation cross.
        $this->Line(11, 12, 14, 12);
        $this->Line(12.5, 10.5, 12.5, 13.5);
        $this->Line(193, 12, 196, 12);
        $this->Line(194.5, 10.5, 194.5, 13.5);
        $this->SetFont($font, 'B', 14);
        $this->SetXY(15,  15);
        $this->Cell(90, 4, offlinequiz_str_html_pdf(get_string('answerform',  'offlinequiz')), 0, 0, 'C');
        $this->Ln(6);
        $this->SetFont($font, '', 10);
        $this->Cell(90, 6, offlinequiz_str_html_pdf(get_string('forautoanalysis',  'offlinequiz')), 0, 1, 'C');
        $this->Ln(2);
        $this->SetFont($font, '', 8);
        $this->Cell(90, 7, ' '.offlinequiz_str_html_pdf(get_string('firstname')).":", 1, 0, 'L');
        $this->Cell(29, 7, ' '.offlinequiz_str_html_pdf(get_string('invigilator',  'offlinequiz')), 0, 1, 'C');
        $this->Cell(90, 7, ' '.offlinequiz_str_html_pdf(get_string('lastname')).":", 1, 1, 'L');
        $this->Cell(90, 7, ' '.offlinequiz_str_html_pdf(get_string('signature',  'offlinequiz')).":", 1, 1, 'L');
        $this->Ln(5);
        $this->Cell(20, 7, offlinequiz_str_html_pdf(get_string('group', 'offlinequiz')).":", 0, 0, 'L');
        $this->SetXY(34.4,  57.4);

        // Print boxes for groups.
        for ($i = 0; $i <= 5; $i++) {
            $this->Cell(6,  3.5,  $letterstr[$i], 0, 0, 'R');
            $this->Cell(0.85,  1, '', 0, 0, 'R');
            $this->Rect($this->GetX(),  $this->GetY(),  3.5,  3.5);
            $this->Cell(2.7,  1, '', 0, 0, 'C');
            if (!empty($this->group) && $letterstr[$i] == $this->group) {
                $this->Image("$CFG->dirroot/mod/offlinequiz/pix/kreuz.gif", $this->GetX() - 2.75,  $this->Gety() + 0.15,  3.15,  0);
            }
        }

        $this->Ln(10);
        $this->MultiCell(115, 3, offlinequiz_str_html_pdf(get_string('instruction1',  'offlinequiz')), 0, 'L');
        $this->Ln(1);
        $this->SetY(78);
        $this->Cell(42, 8, "", 0, 0, 'C');
        $this->Rect($this->GetX(),  $this->GetY(),  3.5,  3.5);
        $this->Cell(3.5, 3.5, "", 0, 1, 'C');
        $this->Ln(1);
        $this->MultiCell(115, 3, offlinequiz_str_html_pdf(get_string('instruction2',  'offlinequiz')), 0, 'L');
        $this->Image("$CFG->dirroot/mod/offlinequiz/pix/kreuz.gif",  57.2,  78.2,  3.15, 0);   // JZ added 0.4 to y value.
        $this->Image("$CFG->dirroot/mod/offlinequiz/pix/ausstreichen.jpg", 56.8,  93,  4.1, 0);  // JZ added 0.4 to y value.
        $this->SetY(93.1);
        $this->Cell(42, 8, "", 0, 0, 'C');
        $this->Cell(3.5, 3.5, '', 1, 1, 'C');
        $this->Ln(1);
        $this->MultiCell(115, 3, offlinequiz_str_html_pdf(get_string('instruction3',  'offlinequiz')), 0, 'L');

        $this->Line(109, 29, 130, 29);                                 // Rectangle for the teachers to sign.
        $this->Line(109, 50, 130, 50);
        $this->Line(109, 29, 109, 50);
        $this->Line(130, 29, 130, 50);

        $this->SetFont($font, 'B', 10);
        $this->SetXY(137, 27);
        $this->Cell($offlinequizconfig->ID_digits * 6.5, 7,
                    offlinequiz_str_html_pdf(get_string('idnumber',  'offlinequiz')), 0, 1, 'C');
        $this->SetXY(137, 34);
        $this->Cell($offlinequizconfig->ID_digits * 6.5, 7, '', 1, 1, 'C');  // Box for ID number.

        for ($i = 1; $i < $offlinequizconfig->ID_digits; $i++) {      // Little lines to separate the digits.
            $this->Line(137 + $i * 6.5, 39, 137 + $i * 6.5, 41);
        }

        $this->SetDrawColor(150);
        $this->Line(137,  47.7,  138 + $offlinequizconfig->ID_digits * 6.5,  47.7);  // Line to sparate 0 from the other.
        $this->SetDrawColor(0);

        // Print boxes for the user ID number.
        $this->SetFont($font, '', 12);
        for ($i = 0; $i < $offlinequizconfig->ID_digits; $i++) {
            $x = 139 + 6.5 * $i;
            for ($j = 0; $j <= 9; $j++) {
                $y = 44 + $j * 6;
                $this->Rect($x, $y, 3.5, 3.5);
            }
        }

        // Print the digits for the user ID number.
        $this->SetFont($font, '', 10);
        for ($y = 0; $y <= 9; $y++) {
            $this->SetXY(134, ($y * 6 + 44));
            $this->Cell(3.5, 3.5, "$y", 0, 1, 'C');
            $this->SetXY(138 + $offlinequizconfig->ID_digits * 6.5, ($y * 6 + 44));
            $this->Cell(3.5, 3.5, "$y", 0, 1, 'C');
        }

        $this->Ln();
    }

    private function get_logo_aspect_ratio($logourl) {
        list($originalwidth, $originalheight) = getimagesize($logourl);
        return $originalwidth / $originalheight;
    }


    /**
     * (non-PHPdoc)
     * @see TCPDF::Footer()
     */
    // @codingStandardsIgnoreLine  This function name is not moodle-standard but I need to overwrite TCPDF
    public function Footer() {
        $letterstr = ' ABCDEF';
        $font = offlinequiz_get_pdffont();

        $this->Line(11, 285, 14, 285);
        $this->Line(12.5, 283.5, 12.5, 286.5);
        $this->Line(193, 285, 196, 285);
        $this->Line(194.5, 283.5, 194.5, 286.5);
        $this->Rect(192, 282.5, 2.5, 2.5, 'F');                // Flip indicator.
        $this->Rect(15, 281, 174, 0.5, 'F');                   // Bold line on bottom.

        // Position at x mm from bottom.
        $this->SetY(-20);
        $this->SetFont($font, 'I', 8);
        $this->Cell(10, 4, $this->formtype, 1, 0, 'C');

        // ID of the offline quiz.
        $this->Cell(15, 4, substr('0000000'.$this->offlinequiz, -7), 1, 0, 'C');

        // Letter for the group.
        $this->Cell(10, 4, $letterstr[$this->groupid], 1, 0, 'C');

        // ID of the user who created the form.
        $this->Cell(15, 4, substr('0000000'.$this->userid, -7), 1, 0, 'C');

        // Name of the offline-quiz.
        $title = $this->title;
        $width = 100;

        while ($this->GetStringWidth($title) > ($width - 1)) {
            $title = mb_substr($title,  0,  mb_strlen($title) - 1);
        }
        $this->Cell($width, 4, $title, 1, 0, 'C');

        $y = $this->GetY();
        $x = $this->GetX();
        // Print bar code for page.
        offlinequiz_barcodewriter::print_barcode($this, $this->PageNo(), $x, $y);

        $this->Rect($x, $y, 0.2, 3.7, 'F');

        // Page number.
        $this->Ln(3);
        $this->SetFont($font, 'I', 8);
        $this->Cell(0, 10, offlinequiz_str_html_pdf(get_string('page') . ' ' . $this->getAliasNumPage() . '/' .
                $this->getAliasNbPages()), 0, 0, 'C');
    }
}

/**
 * Returns a rendering of the number depending on the answernumbering format.
 *
 * @param int $num The number, starting at 0.
 * @param string $style The style to render the number in. One of the
 * options returned by {@link qtype_multichoice:;get_numbering_styles()}.
 * @return string the number $num in the requested style.
 */
function number_in_style($num, $style) {
        return chr(ord('a') + $num);
}

/**
 * prints the question to the pdf
 */
function offlinequiz_print_question_html($pdf, $question, $texfilter, $trans, $offlinequiz) {
    $pdf->checkpoint();

    $questiontext = $question->questiontext;

    // Filter only for tex formulas.
    if (!empty($texfilter)) {
        $questiontext = $texfilter->filter($questiontext);
    }
    if($question->questiontextformat == FORMAT_PLAIN) {
        $questiontext = s($questiontext);
    }
    // Remove all HTML comments (typically from MS Office).
    $questiontext = preg_replace("/<!--.*?--\s*>/ms", "", $questiontext);

    // Remove <font> tags.
    $questiontext = preg_replace("/<font[^>]*>[^<]*<\/font>/ms", "", $questiontext);

    // Remove <script> tags that are created by mathjax preview.
    $questiontext = preg_replace("/<script[^>]*>[^<]*<\/script>/ms", "", $questiontext);

    // Remove all class info from paragraphs because TCPDF won't use CSS.
    $questiontext = preg_replace('/<p[^>]+class="[^"]*"[^>]*>/i', "<p>", $questiontext);

    $questiontext = $trans->fix_image_paths($questiontext, $question->contextid, 'questiontext', $question->id,
        1, 300, $offlinequiz->disableimgnewlines);

    $html = '';

    $html .= $questiontext . '<br/><br/>';
    return $html;
}

function offlinequiz_get_answers_html($offlinequiz, $templateusage,
    $slot, $question, $texfilter, $trans, $correction) {
    $html = '';
    $slotquestion = $templateusage->get_question ( $slot );
    // There is only a slot for multichoice questions.
    $attempt = $templateusage->get_question_attempt($slot);
    $order = $slotquestion->get_order($attempt);  // Order of the answers.

    foreach ($order as $key => $answer) {
        $answertext = $question->options->answers[$answer]->answer;
        // Filter only for tex formulas.
        if (!empty($texfilter)) {
            $answertext = $texfilter->filter($answertext);
        }
        if($question->options->answers[$answer]->answerformat == FORMAT_PLAIN) {
            $answertext = s($answertext);
        }
        // Remove all HTML comments (typically from MS Office).
        $answertext = preg_replace("/<!--.*?--\s*>/ms", "", $answertext);
        // Remove all paragraph tags because they mess up the layout.
        $answertext = preg_replace("/<p[^>]*>/ms", "", $answertext);
        // Remove <script> tags that are created by mathjax preview.
        $answertext = preg_replace("/<script[^>]*>[^<]*<\/script>/ms", "", $answertext);
        $answertext = preg_replace("/<\/p[^>]*>/ms", "", $answertext);
        $answertext = $trans->fix_image_paths($answertext, $question->contextid, 'answer', $answer,
            1, 300, $offlinequiz->disableimgnewlines);

        if ($correction) {
            if ($question->options->answers[$answer]->fraction > 0) {
                $html .= '<b>';
            }

            $answertext .= " (".round($question->options->answers[$answer]->fraction * 100)."%)";
        }

        $html .= number_in_style($key, $question->options->answernumbering) . ') &nbsp; ';
        $html .= $answertext;

        if ($correction) {
            if ($question->options->answers[$answer]->fraction > 0) {
                $html .= '</b>';
            }
        }

        $html .= "<br/>\n";
    }

    $infostring = offlinequiz_get_question_infostring($offlinequiz, $question);
    if ($infostring) {
        $html .= '<br/>' . $infostring . '<br/>';
    }
    return $html;
}

function offlinequiz_write_question_to_pdf($pdf, $fontsize, $questiontype, $html, $number) {

    $pdf->writeHTMLCell(165,  round($fontsize / 2), $pdf->GetX(), $pdf->GetY() + 0.3, $html);
    $pdf->Ln();

    if ($pdf->is_overflowing()) {
        $font = offlinequiz_get_pdffont();
        $pdf->backtrack();
        $pdf->AddPage();
        $pdf->Ln(14);

        // Print the question number and the HTML string again on the new page.
        if ($questiontype == 'multichoice' || $questiontype == 'multichoiceset') {
            $pdf->SetFont($font, 'B', $fontsize);
            $pdf->Cell(4, round($fontsize / 2), "$number)  ", 0, 0, 'R');
            $pdf->SetFont($font, '', $fontsize);
        }

        $pdf->writeHTMLCell(165,  round($fontsize / 2), $pdf->GetX(), $pdf->GetY() + 0.3, $html);
        $pdf->Ln();
    }
}
/**
 * Generates the PDF question/correction form for an offlinequiz group.
 *
 * @param question_usage_by_activity $templateusage the template question  usage for this offline group
 * @param object $offlinequiz The offlinequiz object
 * @param object $group the offline group object
 * @param int $courseid the ID of the Moodle course
 * @param object $context the context of the offline quiz.
 * @param boolean correction if true the correction form is generated.
 * @return stored_file instance, the generated PDF file.
 */
function offlinequiz_create_pdf_question(question_usage_by_activity $templateusage, $offlinequiz, $group,
                                         $courseid, $context, $correction = false) {
    global $CFG, $DB, $OUTPUT;

    $letterstr = 'abcdefghijklmnopqrstuvwxyz';
    $groupletter = strtoupper($letterstr[$group->groupnumber - 1]);
    $font = offlinequiz_get_pdffont($offlinequiz);
    $coursecontext = context_course::instance($courseid);

    $pdf = new offlinequiz_question_pdf('P', 'mm', 'A4');
    $trans = new offlinequiz_html_translator();

    $title = offlinequiz_str_html_pdf($offlinequiz->name);
    if (!empty($offlinequiz->time)) {
        $title .= ": ".offlinequiz_str_html_pdf(userdate($offlinequiz->time));
    }
    $title .= ",  ".offlinequiz_str_html_pdf(get_string('group', 'offlinequiz')." $groupletter");
    $pdf->set_title($title);
    $pdf->SetMargins(15, 28, 15);
    $pdf->SetAutoPageBreak(false, 25);
    $pdf->AddPage();

    // Print title page.
    $pdf->SetFont($font, 'B', 14);
    $pdf->Ln(4);
    if (!$correction) {
        $pdf->Cell(0, 4, offlinequiz_str_html_pdf(get_string('questionsheet', 'offlinequiz')), 0, 0, 'C');
        if ($offlinequiz->printstudycodefield) {
            $pdf->Rect(34, 42, 137, 50, 'D');
        } else {
            $pdf->Rect(34, 42, 137, 40, 'D');
        }
        $pdf->SetFont($font, '', 10);
        // Line breaks to position name string etc. properly.
        $pdf->Ln(14);
        $pdf->Cell(58, 10, offlinequiz_str_html_pdf(get_string('name')) . ":", 0, 0, 'R');
        $pdf->Rect(76, 54, 80, 0.3, 'F');
        $pdf->Ln(10);
        $pdf->Cell(58, 10, offlinequiz_str_html_pdf(get_string('idnumber', 'offlinequiz')) . ":", 0, 0, 'R');
        $pdf->Rect(76, 64, 80, 0.3, 'F');
        $pdf->Ln(10);
        if ($offlinequiz->printstudycodefield) {
            $pdf->Cell(58, 10, offlinequiz_str_html_pdf(get_string('studycode', 'offlinequiz')) . ":", 0, 0, 'R');
            $pdf->Rect(76, 74, 80, 0.3, 'F');
            $pdf->Ln(10);
        }
        $pdf->Cell(58, 10, offlinequiz_str_html_pdf(get_string('signature', 'offlinequiz')) . ":", 0, 0, 'R');
        if ($offlinequiz->printstudycodefield) {
            $pdf->Rect(76, 84, 80, 0.3, 'F');
        } else {
            $pdf->Rect(76, 74, 80, 0.3, 'F');
        }
        $pdf->Ln(25);
        $pdf->SetFont($font, '', $offlinequiz->fontsize);

        // The PDF intro text can be arbitrarily long so we have to catch page overflows.
        if (!empty($offlinequiz->pdfintro)) {
            $oldx = $pdf->GetX();
            $oldy = $pdf->GetY();

            $pdf->checkpoint();
            $pdf->writeHTMLCell(165, round($offlinequiz->fontsize / 2), $pdf->GetX(), $pdf->GetY(), $offlinequiz->pdfintro);
            $pdf->Ln();

            if ($pdf->is_overflowing()) {
                $pdf->backtrack();
                $pdf->SetX($oldx);
                $pdf->SetY($oldy);
                $paragraphs = preg_split('/<p>/', $offlinequiz->pdfintro);

                foreach ($paragraphs as $paragraph) {
                    if (!empty($paragraph)) {
                        $sentences = preg_split('/<br\s*\/>/', $paragraph);
                        foreach ($sentences as $sentence) {
                            $pdf->checkpoint();
                            $pdf->writeHTMLCell(165, round($offlinequiz->fontsize / 2), $pdf->GetX(), $pdf->GetY(),
                                                $sentence . '<br/>');
                            $pdf->Ln();
                            if ($pdf->is_overflowing()) {
                                $pdf->backtrack();
                                $pdf->AddPage();
                                $pdf->Ln(14);
                                $pdf->writeHTMLCell(165, round($offlinequiz->fontsize / 2), $pdf->GetX(), $pdf->GetY(), $sentence);
                                $pdf->Ln();
                            }
                        }
                    }
                }
            }
        }
        $pdf->AddPage();
        $pdf->Ln(2);
    }
    $pdf->SetMargins(15, 15, 15);

    // Load all the questions needed for this offline quiz group.
    $sql = "SELECT q.*, c.contextid, ogq.page, ogq.slot, ogq.maxmark
              FROM {offlinequiz_group_questions} ogq
              JOIN {question} q ON q.id = ogq.questionid
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} c ON c.id = qbe.questioncategoryid
             WHERE ogq.offlinequizid = :offlinequizid
               AND ogq.offlinegroupid = :offlinegroupid
          ORDER BY ogq.slot ASC ";
    $params = array('offlinequizid' => $offlinequiz->id, 'offlinegroupid' => $group->id);

    // Load the questions.
    $questions = $DB->get_records_sql($sql, $params);
    if (!$questions) {
        echo $OUTPUT->box_start();
        echo $OUTPUT->error_text(get_string('noquestionsfound', 'offlinequiz', $groupletter));
        echo $OUTPUT->box_end();
        return;
    }

    // Load the question type specific information.
    if (!get_question_options($questions)) {
        throw new \moodle_exception('Could not load question options');
    }

    // Restore the question sessions to their most recent states.
    // Creating new sessions where required.
    $number = 1;

    // We need a mapping from question IDs to slots, assuming that each question occurs only once.
    $slots = $templateusage->get_slots();

    $texfilter = new \filter_tex\text_filter($context, []);

    // If shufflequestions has been activated we go through the questions in the order determined by
    // the template question usage.
    if ($offlinequiz->shufflequestions) {
        foreach ($slots as $slot) {
            $slotquestion = $templateusage->get_question($slot);
            $currentquestionid = $slotquestion->id;

            // Add page break if necessary because of overflow.
            if ($pdf->GetY() > 230) {
                $pdf->AddPage();
                $pdf->Ln(14);
            }
            set_time_limit(120);
            $question = $questions[$currentquestionid];

            $html = offlinequiz_print_question_html($pdf, $question, $texfilter, $trans, $offlinequiz);

            if ($question->qtype == 'multichoice' || $question->qtype == 'multichoiceset') {

                $html = $html . offlinequiz_get_answers_html($offlinequiz, $templateusage,
                    $slot, $question, $texfilter, $trans, $correction);

            }
            if ($offlinequiz->disableimgnewlines) {
                // This removes span attribute added by TEX filter which created extra line break after every LATEX formula.
                $html = preg_replace("/(<span class=\"MathJax_Preview\">.+?)+(title=\"TeX\" >)/ms", "", $html);
                $html = preg_replace("/<\/a><\/span>/ms", "", $html);
                $html = preg_replace("/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/ms", "", $html);
            }
            // Finally print the question number and the HTML string.
            if ($question->qtype == 'multichoice' || $question->qtype == 'multichoiceset') {
                $pdf->SetFont($font, 'B', $offlinequiz->fontsize);
                $pdf->Cell(4, round($offlinequiz->fontsize / 2), "$number)  ", 0, 0, 'R');
                $pdf->SetFont($font, '', $offlinequiz->fontsize);
            }
            offlinequiz_write_question_to_pdf($pdf, $offlinequiz->fontsize, $question->qtype, $html, $number);
            $number += $questions[$currentquestionid]->length;
        }
    } else {
        // No shufflequestions, so go through the questions as they have been added to the offlinequiz group.
        // We also have to show description questions that are not in the template.

        // First, compute mapping  questionid -> slotnumber.
        $questionslots = array();
        foreach ($slots as $slot) {
            $questionslots[$templateusage->get_question($slot)->id] = $slot;
        }
        $currentpage = 1;
        foreach ($questions as $question) {
            $currentquestionid = $question->id;

            // Add page break if set explicitely by teacher.
            if ($question->page > $currentpage) {
                $pdf->AddPage();
                $pdf->Ln(14);
                $currentpage++;
            }

            // Add page break if necessary because of overflow.
            if ($pdf->GetY() > 230) {
                $pdf->AddPage();
                $pdf->Ln( 14 );
            }
            set_time_limit( 120 );

            // Either we print the question HTML.
            $html = offlinequiz_print_question_html($pdf, $question, $texfilter, $trans, $offlinequiz);

            if ($question->qtype == 'multichoice' || $question->qtype == 'multichoiceset') {

                $slot = $questionslots[$currentquestionid];

                $html = $html . offlinequiz_get_answers_html($offlinequiz, $templateusage,
                    $slot, $question, $texfilter, $trans, $correction);
            }

            // Finally print the question number and the HTML string.
            if ($question->qtype == 'multichoice' || $question->qtype == 'multichoiceset') {
                $pdf->SetFont ($font, 'B', $offlinequiz->fontsize );
                $pdf->Cell ( 4, round ( $offlinequiz->fontsize / 2 ), "$number)  ", 0, 0, 'R' );
                $pdf->SetFont ($font, '', $offlinequiz->fontsize );
            }

            // This removes span attribute added by TEX filter which created extra line break after every LATEX formula.
            if ($offlinequiz->disableimgnewlines) {
                $html = preg_replace("/(<span class=\"MathJax_Preview\">.+?)+(title=\"TeX\" >)/ms", "", $html);
                $html = preg_replace("/<\/a><\/span>/ms", "", $html);
                $html = preg_replace("/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/ms", "", $html);
            }

            offlinequiz_write_question_to_pdf($pdf, $offlinequiz->fontsize, $question->qtype, $html, $number);
            $number += $questions[$currentquestionid]->length;
        }

    }

    $fs = get_file_storage();

    $fileprefix = get_string('fileprefixform', 'offlinequiz');
    if ($correction) {
        $fileprefix = get_string('fileprefixcorrection', 'offlinequiz');
    }

    // Prepare file record object.
    $date = usergetdate(time());
    $timestamp = sprintf('%04d%02d%02d_%02d%02d%02d',
            $date['year'], $date['mon'], $date['mday'], $date['hours'], $date['minutes'], $date['seconds']);

    $fileinfo = array(
            'contextid' => $context->id,
            'component' => 'mod_offlinequiz',
            'filearea' => 'pdfs',
            'filepath' => '/',
            'itemid' => 0,
            'filename' => $fileprefix . '_' . $groupletter . '_' . $timestamp . '.pdf');

    if ($oldfile = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
            $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename'])) {

        $oldfile->delete();
    }
    $pdfstring = $pdf->Output('', 'S');

    $file = $fs->create_file_from_string($fileinfo, $pdfstring);
    $trans->remove_temp_files();

    return $file;
}


/*
 * Generates the PDF answer form for an offlinequiz group.
*
* @param int $maxanswers the maximum number of answers in all question of the offline group
* @param question_usage_by_activity $templateusage the template question  usage for this offline group
* @param object $offlinequiz The offlinequiz object
* @param object $group the offline group object
* @param int $courseid the ID of the Moodle course
* @param object $context the context of the offline quiz.
* @return stored_file instance, the generated PDF file.
*/
function offlinequiz_create_pdf_answer($maxanswers, $templateusage, $offlinequiz, $group, $courseid, $context) {
    global $CFG, $DB, $OUTPUT, $USER;

    $letterstr = ' abcdefghijklmnopqrstuvwxyz';
    $groupletter = strtoupper($letterstr[$group->groupnumber]);
    $font = offlinequiz_get_pdffont($offlinequiz);

    $fm = new stdClass();
    $fm->q = 0;
    $fm->a = 0;

    $texfilter = new filter_tex\text_filter($context, array());

    $pdf = new offlinequiz_answer_pdf('P', 'mm', 'A4');
    $title = offlinequiz_str_html_pdf($offlinequiz->name);
    if (!empty($offlinequiz->time)) {
        $title = $title . ": " . offlinequiz_str_html_pdf(userdate($offlinequiz->time));
    }
    $pdf->set_title($title);
    $pdf->group = $groupletter;
    $pdf->groupid = $group->groupnumber;
    $pdf->offlinequiz = $offlinequiz->id;
    $pdf->formtype = 4;
    $pdf->colwidth = 7 * 6.5;
    if ($maxanswers > 5) {
        $pdf->formtype = 3;
        $pdf->colwidth = 9 * 6.5;
    }
    if ($maxanswers > 7) {
        $pdf->formtype = 2;
        $pdf->colwidth = 14 * 6.5;
    }
    if ($maxanswers > 12) {
        $pdf->formtype = 1;
        $pdf->colwidth = 26 * 6.5;
    }
    if ($maxanswers > 26) {
        throw new \moodle_exception('Too many answers in one question');
    }
    $pdf->userid = $USER->id;
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // Load all the questions and quba slots needed by this script.
    $slots = $templateusage->get_slots();

    $sql = "SELECT q.*, c.contextid, ogq.page, ogq.slot, ogq.maxmark
              FROM {offlinequiz_group_questions} ogq
              JOIN {question} q ON ogq.questionid = q.id
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} c ON qbe.questioncategoryid = c.id
             WHERE ogq.offlinequizid = :offlinequizid
               AND ogq.offlinegroupid = :offlinegroupid
          ORDER BY ogq.slot ASC ";
    $params = array('offlinequizid' => $offlinequiz->id, 'offlinegroupid' => $group->id);

    if (!$questions = $DB->get_records_sql($sql, $params)) {
        echo $OUTPUT->box_start();
        echo $OUTPUT->error_text(get_string('noquestionsfound', 'offlinequiz', $groupletter));
        echo $OUTPUT->box_end();
        return;
    }

    // Load the question type specific information.
    if (!get_question_options($questions)) {
        throw new \moodle_exception('Could not load question options');
    }

    // Counting the total number of multichoice questions in the question usage.
    $totalnumber = offlinequiz_count_multichoice_questions($templateusage);

    $number = 0;
    $col = 1;
    $offsety = 105.5;
    $offsetx = 17.3;
    $page = 1;

    $pdf->SetY($offsety);

    $pdf->SetFont($font, 'B', 10);
    foreach ($slots as $key => $slot) {
        set_time_limit(120);
        $slotquestion = $templateusage->get_question($slot);
        $currentquestionid = $slotquestion->id;
        $attempt = $templateusage->get_question_attempt($slot);
        $order = $slotquestion->get_order($attempt);  // Order of the answers.

        // Get the question data.
        $question = $questions[$currentquestionid];

        // Only look at multichoice questions.
        if ($question->qtype != 'multichoice' && $question->qtype != 'multichoiceset') {
            continue;
        }

        // Print the answer letters every 8 questions.
        if ($number % 8 == 0) {
            $pdf->SetFont($font, '', 8);
            $pdf->SetX(($col - 1) * ($pdf->colwidth) + $offsetx + 5);
            for ($i = 0; $i < $maxanswers; $i++) {
                $pdf->Cell(3.5, 3.5, number_in_style($i, $question->options->answernumbering), 0, 0, 'C');
                $pdf->Cell(3, 3.5, '', 0, 0, 'C');
            }
            $pdf->Ln(4.5);
            $pdf->SetFont($font, 'B', 10);
        }

        $pdf->SetX(($col - 1) * ($pdf->colwidth) + $offsetx);

        $pdf->Cell(5, 1, ($number + 1).")  ", 0, 0, 'R');

        // Print one empty box for each answer.
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        for ($i = 1; $i <= count($order); $i++) {
            // Move the boxes slightly down to align with question number.
            $pdf->Rect($x, $y + 0.6, 3.5, 3.5, '', array('all' => array('width' => 0.2)));
            $x += 6.5;
        }

        $pdf->SetX($x);

        $pdf->Ln(6.5);

        // Switch to next column if necessary.
        if (($number + 1) % 24 == 0) {
            $pdf->SetY($offsety);
            $col++;
            // Do a pagebreak if necessary.
            if ($col > $pdf->formtype and ($number + 1) < $totalnumber) {
                $col = 1;
                $pdf->AddPage();
                $page++;
                $pdf->SetY($offsety);
            }
        }
        $number ++;
    }

    $group->numberofpages = $page;
    $DB->update_record('offlinequiz_groups', $group);

    $fs = get_file_storage();

    // Prepare file record object.
    $date = usergetdate(time());
    $timestamp = sprintf('%04d%02d%02d_%02d%02d%02d',
            $date['year'], $date['mon'], $date['mday'], $date['hours'], $date['minutes'], $date['seconds']);

    $fileprefix = get_string('fileprefixanswer', 'offlinequiz');
    $fileinfo = array(
            'contextid' => $context->id,
            'component' => 'mod_offlinequiz',
            'filearea' => 'pdfs',
            'filepath' => '/',
            'itemid' => 0,
            'filename' => $fileprefix . '_' . $groupletter . '_' . $timestamp . '.pdf');

    if ($oldfile = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
            $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename'])) {
        $oldfile->delete();
    }
    $pdfstring = $pdf->Output('', 'S');
    $file = $fs->create_file_from_string($fileinfo, $pdfstring);
    return $file;
}

/**
 * Creates the correction PDF form for an offlinequiz.
 *
 * @param object $templateusage The template usage object
 * @param object $offlinequiz The offlinequiz object
 * @param object $group The group object
 * @param int $courseid The course ID
 * @param object $context The context object
 * @return \stored_file|null
 */
function offlinequiz_create_pdf_correction($templateusage, $offlinequiz, $group, $courseid, $context) {
    global $CFG;

   

    $pdf = new offlinequiz_pdf();
    $pdf->SetTitle(get_string('correctionform', 'mod_offlinequiz'));
    $pdf->SetSubject($offlinequiz->name);
    $pdf->SetCreator('Moodle');
    $pdf->SetAuthor(get_string('pluginname', 'mod_offlinequiz'));
    $pdf->SetKeywords('Moodle, offlinequiz, correction');

    $pdf->AddPage();

    // Add header.
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, get_string('correctionform', 'mod_offlinequiz'), 0, 1, 'C');
    $pdf->Ln(10);

    // Add quiz and group info.
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, get_string('quiz', 'mod_offlinequiz') . ': ' . $offlinequiz->name, 0, 1);
    $pdf->Cell(0, 10, get_string('group', 'mod_offlinequiz') . ': ' . $group->groupnumber, 0, 1);
    $pdf->Ln(10);

    // Add table header.
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(40, 10, get_string('question', 'mod_offlinequiz'), 1);
    $pdf->Cell(40, 10, get_string('points', 'mod_offlinequiz'), 1);
    $pdf->Cell(100, 10, get_string('comment', 'mod_offlinequiz'), 1);
    $pdf->Ln();

    // Add table rows for each question.
    $pdf->SetFont('helvetica', '', 12);
    $slots = $templateusage->get_slots();
    foreach ($slots as $slot) {
        $question = $templateusage->get_question($slot);
        $pdf->Cell(40, 10, $question->name, 1);
        $pdf->Cell(40, 10, '', 1);
        $pdf->Cell(100, 10, '', 1);
        $pdf->Ln();
    }

    // Save the PDF to a temporary file.
    $tempdir = make_temp_directory('offlinequiz_');
    $filename = 'correction_' . $group->groupnumber . '.pdf';
    $filepath = $tempdir . '/' . $filename;
    $pdf->Output($filepath, 'F');

    // Store the file in Moodle.
    $fs = get_file_storage();
    $filerecord = [
        'contextid' => $context->id,
        'component' => 'mod_offlinequiz',
        'filearea' => 'correction',
        'itemid' => $group->id,
        'filepath' => '/',
        'filename' => $filename,
        'userid' => $offlinequiz->teacher,
    ];

    if ($fs->file_exists($filerecord['contextid'], $filerecord['component'], $filerecord['filearea'],
        $filerecord['itemid'], $filerecord['filepath'], $filerecord['filename'])) {
        $existingfile = $fs->get_file($filerecord['contextid'], $filerecord['component'],
            $filerecord['filearea'], $filerecord['itemid'], $filerecord['filepath'], $filerecord['filename']);
        $existingfile->delete();
    }
    
    $storedfile = $fs->create_file_from_pathname($filerecord, $filepath);
    
    // Clean up temp file
    unlink($filepath);
    rmdir($tempdir);

    return $storedfile;
}