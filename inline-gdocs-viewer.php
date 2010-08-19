<?php
/*
Plugin Name: Inline Google Spreadsheet Viewer
Plugin URI: http://maymay.net/blog/projects/inline-google-spreadsheet-viewer/
Description: Retrieves a published, public Google Spreadsheet and displays it as an HTML table.
Version: 0.1
Author: Mr. Meitar Moscovitz
Author URI: http://maymay.net/
*/

/**
 * @copyright 2010 Meitar Moscovitz
 */

// Uncomment for assistance from WordPress in debugging.
define('WP_DEBUG', true);

// Filesystem path to this plugin.
define('GDOCS_VIEWER_PATH', realpath(ABSPATH.'wp-content/plugins/'.dirname(plugin_basename(__FILE__))));

class InlineGoogleSpreadsheetViewerPlugin {

    /**
     * Constructor.
     */
    function InlineGoogleSpreadsheetViewerPlugin () {
        // empty for now
    }

    /**
     * Function csvToHtml grabs CSV data from a URL and returns an HTML table.
     *
     * @param $options array Values passed from the shortcode.
     * @param $caption string Passed via shortcode, should be the table caption.
     * @return An HTML string if successful, false otherwise.
     * @see displayShortcode
     */
    function csvToHtml ($options, $caption) {
        if (!$options['key']) { return false; }
        $url = "https://spreadsheets.google.com/pub?key={$options['key']}&output=csv";
        $h  = fopen($url, 'r');
        $r = array();
        while (($x = fgetcsv($h)) !== false) {
            $r[] = $x;
        }
        if ($options['strip'] > 0) {
            for ($i = 0; $i < $options['strip']; $i++) {
                array_shift($r); // discard
            }
        }

        $ir = 1; // row number counter
        $ic = 1; // column number counter

        $html  = "<table class=\"igsv-table\" summary=\"{$options['summary']}\">";
        if (!empty($caption)) {
            $html .= "<caption>$caption</caption>";
        }
        $html .= "<thead><tr class=\"row-$ir " . $this->evenOrOdd($ir) . "\">";
        $ir++;
        $table_head = array_shift($r);
        foreach ($table_head as $v) {
            $html .= "<th class=\"col-$ic " . $this->evenOrOdd($ic) . "\"><div>$v</div></th>";
            $ic++;
        }
        $html .= "</tr></thead><tbody>";
        foreach ($r as $v) {
            $html .= "<tr class=\"row-$ir " . $this->evenOrOdd($ir) . "\">";
            $ir++;
            $ic = 1; // reset column counting
            foreach ($v as $td) {
                $html .= "<td class=\"col-$ic " . $this->evenOrOdd($ic) . "\">$td</td>";
                $ic++;
            }
            $html .= "</tr>";
        }
        $html .= '</tbody></table>';

        return $html;
    }

    function evenOrOdd ($x) {
        return ((int) $x % 2) ? 'odd' : 'even'; // cast to integer just in case
    }

    /**
     * WordPress Shortcode handler.
     */
    function displayShortcode ($atts, $content = null) {
        $x = shortcode_atts(array(
            'key'      => false,                // Google Doc ID
            'sheet_id' => false,                // Sheet ID for a Google Spreadsheet, if only one
            'summary'  => 'Google Spreadsheet', // If spreadsheet, value for summary attribute
            'strip'    => 0                     // If spreadsheet, how many rows to omit from top
        ), $atts);

        return $this->csvToHtml($x, $content);
    }
}

$inline_gdoc_viewer = new InlineGoogleSpreadsheetViewerPlugin();
add_shortcode('gdoc', array($inline_gdoc_viewer, 'displayShortcode'));
