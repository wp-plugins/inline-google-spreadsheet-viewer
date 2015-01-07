<?php
/**
 * Plugin Name: Inline Google Spreadsheet Viewer
 * Plugin URI: http://maymay.net/blog/projects/inline-google-spreadsheet-viewer/
 * Description: Retrieves a published, public Google Spreadsheet and displays it as an HTML table or interactive chart. <strong>Like this plugin? Please <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=TJLPJYXHSRBEE&amp;lc=US&amp;item_name=Inline%20Google%20Spreadsheet%20Viewer&amp;item_number=Inline%20Google%20Spreadsheet%20Viewer&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" title="Send a donation to the developer of Inline Google Spreadsheet Viewer">donate</a>. &hearts; Thank you!</strong>
 * Version: 0.8.1
 * Author: Meitar Moscovitz <meitar@maymay.net>
 * Author URI: http://maymay.net/
 * Text Domain: inline-gdocs-viewer
 * Domain Path: /languages
 */

class InlineGoogleSpreadsheetViewerPlugin {

    private $shortcode = 'gdoc';
    private $invocations = 0;

    public function __construct () {
        add_action('plugins_loaded', array($this, 'registerL10n'));
        add_action('admin_head', array($this, 'doAdminHeadActions'));
        add_action('admin_enqueue_scripts', array($this, 'addAdminScripts'));
        add_action('admin_print_footer_scripts', array($this, 'addQuickTagButton'));

        add_shortcode($this->shortcode, array($this, 'displayShortcode'));
    }

    public function registerL10n () {
        load_plugin_textdomain('inline-gdocs-viewer', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function doAdminHeadActions () {
        $this->registerContextualHelp();
    }

    public function addAdminScripts () {
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-dialog');
    }

    /**
     * Deterministically makes a unique transient name.
     *
     * @param string $key The ID of the document, extracted from the key attribute of the shortcode.
     * @param string $q The query, if one exists, from the query attribute of the shortcode.
     * @return string A 40 character unique string representing the name of the transient for this key and query.
     * @see https://codex.wordpress.org/Transients_API
     */
    private function getTransientName ($key, $q, $gid) {
        return substr($this->shortcode . hash('sha1', $this->shortcode . $key . $q . $gid), 0, 40);
    }

    /**
     * This simple getter/setter pair works around a bug in WP's own
     * serialization, apparently, by serializing the data ourselves
     * and then base64 encoding it.
     */
    private function getTransient ($transient) {
        return unserialize(base64_decode(get_transient($transient)));
    }
    private function setTransient ($transient, $data, $expiry) {
        return set_transient($transient, base64_encode(serialize($data)), $expiry);
    }

    /**
     * Lazily tests whether the provided key is likely a
     * Google Spreadsheet or if it's a URL for a file.
     *
     * This is later used to determine whether we load the
     * sheet shortcode or the Google Docs Viewer's <iframe>.
     */
    private function isGoogleSpreadsheetKey ($key) {
        $is_sheet = true;
        $key_parts = parse_url($key);
        if (isset($key_parts['path'])) {
            $path_info = pathinfo($key_parts['path']);
            if (!empty($path_info['extension'])) {
                $is_sheet = false;
            }
        }
        return $is_sheet;
    }

    private function getDocUrl ($key, $gid, $query) {
        $url = '';
        // Assume a full link.
        $m = array();
        if (preg_match('/\/(edit|pubhtml).*$/', $key, $m) && 'http' === substr($key, 0, 4)) {
            $parts = parse_url($key);
            $key = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
            $action = ($query)
                // Due to shortcode parsing limitations of angle brackets (< and > characters),
                // manually decode only the URL encoded values for those values, which are
                // themselves expected to be entered manually by the user. That is, to supply
                // the shortcode with a less than sign, the user ought enter %3C, but after
                // the initial urlencode($query), this will encode the percent sign, returning
                // instead the value %253C, so we manually replace this in the query ourselves.
                ? 'gviz/tq?tqx=out:csv&tq=' . str_replace('%253E', '%3E', str_replace('%253C', '%3C', urlencode($query)))
                : 'export?format=csv';
            $url = str_replace($m[1], $action, $key);
            if ($gid) {
                $url .= '&gid=' . $gid;
            }
        } else {
            $url .= "https://spreadsheets.google.com/pub?key=$key&output=csv";
            if ($gid) {
                $url .= "&single=true&gid=$gid";
            }
        }
        return $url;
    }

    private function getDocKey ($key) {
        // Assume a full link.
        if ('http' === substr($key, 0, 4)) {
            $m = array();
            preg_match('/docs\.google\.com\/spreadsheets\/d\/([^\/]*)/i', $key, $m);
            $key = $m[1];
        }
        return esc_attr($key);
    }

    private function fetchData ($url) {
        $resp = wp_remote_get($url);
        if (is_wp_error($resp)) { // bail on error
            throw new Exception('[' . __('Error requesting Google Spreadsheet data:', 'inline-gdocs-viewer') . $resp->get_error_message() . ']');
        }
        return $resp;
    }

    private function parseCsv ($csv_str) {
        return $this->str_getcsv($csv_str); // Yo, why is PHP's built-in str_getcsv() frakking things up?
    }

    private function parseHtml ($html_str, $gid = 0) {
        $ret = array();

        $dom = new DOMDocument();
        @$dom->loadHTML($html_str);
        $tables = $dom->getElementsByTagName('table');

        // Error early, if no tables were found.
        if (0 === $tables->length) {
            throw new Exception('[' . __('Error loading Google Spreadsheet data. Make sure your Google Spreadsheet is shared <a href="https://support.google.com/drive/answer/2494886?p=visibility_options">using either the "Public on the web" or "Anyone with the link" options</a>.', 'inline-gdocs-viewer') . ']');
        }

        for ($i = 0; $i < $tables->length; $i++) {
            $rows = $tables->item($i)->getElementsByTagName('tr');
            for ($z = 0; $z < $rows->length; $z++) {
                $ths = $rows->item($z)->getElementsByTagName('th');
                foreach ($ths as $k => $node) {
                    $ret[$i][$z][$k] = $node->nodeValue;
                }
                $tds = $rows->item($z)->getElementsByTagName('td');
                foreach ($tds as $k => $node) {
                    $ret[$i][$z][$k] = $node->nodeValue;
                }
            }
        }

        // The 0'th table is the sheet names, the 1'st is the first sheet's data
        array_shift($ret);
        // Only return the correct "sheet."
        return $ret[$gid];
    }

    /**
     * @param $r array Multidimensional array representing table data.
     * @param $options array Values passed from the shortcode.
     * @param $caption string Passed via shortcode, should be the table caption.
     * @return An HTML string of the complete <table> element.
     * @see displayShortcode
     */
    private function dataToHtml ($r, $options, $caption) {
        if ($options['strip'] > 0) { $r = array_slice($r, $options['strip']); } // discard

        // Split into table headers and body.
        $thead = ((int) $options['header_rows']) ? array_splice($r, 0, $options['header_rows']) : array_splice($r, 0, 1);
        $tbody = $r;

        $ir = 1; // row number counter
        $ic = 1; // column number counter

        // Prepend a space character onto the 'class' value, if one exists.
        if (!empty($options['class'])) { $options['class'] = " {$options['class']}"; }
        // Extract the document ID from the key, if a full URL was given.
        $key = $this->getDocKey($options['key']);

        $id = esc_attr($key);
        $class = esc_attr($options['class']);
        $summary = esc_attr($options['summary']);
        $title = esc_attr($options['title']);
        $style = esc_attr($options['style']);
        $html = "<table id=\"igsv-$id\" class=\"igsv-table$class\" summary=\"$summary\" title=\"$title\" style=\"$style\">";
        if (!empty($caption)) {
            $html .= '<caption>' . esc_html($caption) . '</caption>';
        }

        $html .= "<thead>\n";
        foreach ($thead as $v) {
            $html .= "<tr class=\"row-$ir " . $this->evenOrOdd($ir) . "\">";
            $ir++;
            $ic = 1; // reset column counting
            foreach ($v as $th) {
                $th = esc_html($th);
                $html .= "<th class=\"col-$ic " . $this->evenOrOdd($ic) . "\"><div>$th</div></td>";
                $ic++;
            }
            $html .= "</tr>";
        }
        $html .= "</thead><tbody>";

        foreach ($tbody as $v) {
            $html .= "<tr class=\"row-$ir " . $this->evenOrOdd($ir) . "\">";
            $ir++;
            $ic = 1; // reset column counting
            foreach ($v as $td) {
                $td = esc_html($td);
                $html .= "<td class=\"col-$ic " . $this->evenOrOdd($ic) . "\">$td</td>";
                $ic++;
            }
            $html .= "</tr>";
        }
        $html .= '</tbody></table>';

        if (false === $options['linkify'] || 'no' === strtolower($options['linkify'])) {
            return $html;
        } else {
            return make_clickable($html);
        }
    }

    private function evenOrOdd ($x) {
        return ((int) $x % 2) ? 'odd' : 'even'; // cast to integer just in case
    }

    /**
     * Simple CSV parsing, taken directly from PHP manual.
     * @see http://www.php.net/manual/en/function.str-getcsv.php#100579
     */
    private function str_getcsv ($input, $delimiter=',', $enclosure='"', $escape=null, $eol=null) {
        $temp=fopen("php://memory", "rw");
        fwrite($temp, $input);
        fseek($temp, 0);
        $r = array();
        while (($data = fgetcsv($temp, 4096, $delimiter, $enclosure)) !== false) {
            $r[] = $data;
        }
        fclose($temp);
        return $r;
    }

    /**
     * WordPress Shortcode handler.
     */
    public function displayShortcode ($atts, $content = null) {
        $x = shortcode_atts(array(
            'key'      => false,                // Google Doc URL or ID
            'title'    => '',                   // Title (attribute) text or visible chart title
            'class'    => '',                   // Container element's custom class value
            'gid'      => false,                // Sheet ID for a Google Spreadsheet, if only one
            'summary'  => 'Google Spreadsheet', // If spreadsheet, value for summary attribute
            'width'    => '100%',
            'height'   => false,
            'style'    => false,
            'strip'    => 0,                    // If spreadsheet, how many rows to omit from top
            'header_rows' => 1,                 // Number of rows in <thead>
            'use_cache' => true,                // Whether to use Transients API for fetched data.
            // TODO: Make a plugin option setting for default transient expiry time.
            'expire_in' => 10*MINUTE_IN_SECONDS,// Custom time-to-live of cached transient data.
            'linkify'  => true,                 // Whether to run make_clickable() on parsed data.
            'query'    => false,                // Google Visualization Query Language querystring
            'chart'    => false,                // Type of Chart (for an interactive chart)

            // Depending on the type of chart, the following options may be available.
            'chart_aggregation_target'         => false,
            'chart_annotations'                => false,
            'chart_area_opacity'               => false,
            'chart_axis_titles_position'       => false,
            'chart_background_color'           => false,
            'chart_bars'                       => false,
            'chart_bubble'                     => false,
            'chart_candlestick'                => false,
            'chart_chart_area'                 => false,
            'chart_color_axis'                 => false,
            'chart_colors'                     => false,
            'chart_crosshair'                  => false,
            'chart_curve_type'                 => false,
            'chart_data_opacity'               => false,
            'chart_dimensions'                 => false,
            'chart_enable_interactivity'       => false,
            'chart_explorer'                   => false,
            'chart_focus_target'               => false,
            'chart_font_name'                  => false,
            'chart_font_size'                  => false,
            'chart_force_i_frame'              => false,
            'chart_h_axes'                     => false,
            'chart_h_axis'                     => false,
            'chart_height'                     => false,
            'chart_interpolate_nulls'          => false,
            'chart_is_stacked'                 => false,
            'chart_legend'                     => false,
            'chart_line_width'                 => false,
            'chart_orientation'                => false,
            'chart_pie_hole'                   => false,
            'chart_pie_residue_slice_color'    => false,
            'chart_pie_residue_slice_label'    => false,
            'chart_pie_slice_border_color'     => false,
            'chart_pie_slice_text'             => false,
            'chart_pie_slice_text_style'       => false,
            'chart_pie_start_angle'            => false,
            'chart_point_shape'                => false,
            'chart_point_size'                 => false,
            'chart_reverse_categories'         => false,
            'chart_selection_mode'             => false,
            'chart_series'                     => false,
            'chart_size_axis'                  => false,
            'chart_slice_visibility_threshold' => false,
            'chart_slices'                     => false,
            'chart_theme'                      => false,
            'chart_title_position'             => false,
            'chart_title_text_style'           => false,
            'chart_tooltip'                    => false,
            'chart_trendlines'                 => false,
            'chart_v_axis'                     => false,
            'chart_width'                      => false,

            // For some reason this isn't parsing?
            //'chart_is3D'                       => false,
        ), $atts, $this->shortcode);
        if ($this->isGoogleSpreadsheetKey($x['key'])) {
            $output = $this->getSpreadsheetOutput($x, $content);
        } else {
            $output = $this->getGDocsViewerOutput($x);
        }
        $this->invocations++;
        return $output;
    }

    private function getGDocsViewerOutput ($x) {
        $output  = '<iframe src="';
        $output .= esc_attr('https://docs.google.com/viewer?url=' . esc_url($x['key']) . '&embedded=true');
        $output .= '" width="' . esc_attr($x['width']) . '" height="' . esc_attr($x['height']) . '" style="' . esc_attr($x['style']) . '">';
        $output .= esc_html__('Your Web browser must support inline frames to display this content:', 'inline-gdocs-viewer');
        $output .= ' <a href="' . esc_attr($x['key']) . '">' . esc_html($x['title']) . '</a>';
        $output .= '</iframe>';
        return $output;
    }

    private function getSpreadsheetOutput ($x, $content) {
        $url = $this->getDocUrl($x['key'], $x['gid'], $x['query']);
        if (false === $x['chart']) {
            if (false === strpos($x['class'], 'no-datatables')) {
                // Core DataTables.
                wp_enqueue_style(
                    'jquery-datatables',
                    '//cdn.datatables.net/1.10.0/css/jquery.dataTables.css'
                );
                wp_enqueue_script(
                    'jquery-datatables',
                    '//cdn.datatables.net/1.10.0/js/jquery.dataTables.js',
                    'jquery'
                );
                // DataTables extensions.
                wp_enqueue_style(
                    'datatables-colvis',
                    '//cdn.datatables.net/colvis/1.1.0/css/dataTables.colVis.css'
                );
                wp_enqueue_script(
                    'datatables-colvis',
                    '//cdn.datatables.net/colvis/1.1.0/js/dataTables.colVis.min.js',
                    'jquery-datatables'
                );
                wp_enqueue_style(
                    'datatables-tabletools',
                    '//cdn.datatables.net/tabletools/2.2.1/css/dataTables.tableTools.css'
                );
                wp_enqueue_script(
                    'datatables-tabletools',
                    '//cdn.datatables.net/tabletools/2.2.1/js/dataTables.tableTools.min.js',
                    'jquery-datatables'
                );
                wp_enqueue_style(
                    'datatables-fixedcolumns',
                    '//datatables.net/release-datatables/extensions/FixedColumns/css/dataTables.fixedColumns.css'
                );
                wp_enqueue_script(
                    'datatables-fixedcolumns',
                    '//datatables.net/release-datatables/extensions/FixedColumns/js/dataTables.fixedColumns.js',
                    'jquery-datatables'
                );
                wp_enqueue_script(
                    'igsv-datatables',
                    plugins_url('igsv-datatables.js', __FILE__),
                    'jquery-datatables'
                );
            }
            try {
                $data = NULL;
                $transient = $this->getTransientName($x['key'], $x['query'], $x['gid']);
                if (false === $x['use_cache'] || 'no' === strtolower($x['use_cache'])) {
                    delete_transient($transient);
                    $data = $this->fetchData($url);
                } else {
                    if (false === ($data = $this->getTransient($transient))) {
                        $data = $this->fetchData($url);
                        $this->setTransient($transient, $data, (int) $x['expire_in']);
                    }
                }
                $output = $this->displayData($data, $x, $content);
            } catch (Exception $e) {
                $output = $e->getMessage();
            }
        } else {
            // If a chart but no query, just query for entire spreadsheet
            if (false === $x['query']) {
                $url = preg_replace('/export\?format=csv/', 'gviz/tq?', $url); // trailing ? in case of `gid` param
            }
            wp_enqueue_script('google-ajax-api', '//www.google.com/jsapi');
            wp_enqueue_script(
                'igsv-gvizcharts',
                plugins_url('igsv-gvizcharts.js', __FILE__),
                'google-ajax-api'
            );
            $chart_id = 'igsv-' . $this->invocations . '-' . $x['chart'] . 'chart-'  . $this->getDocKey($x['key']);
            $output  = '<div id="' . $chart_id . '" class="igsv-chart" title="' . esc_attr($x['title']) . '"';
            $output .= ' data-chart-type="' . esc_attr(ucfirst($x['chart'])) . '"';
            $output .= ' data-datasource-href="' . esc_attr($url) . '"';
            if ($chart_opts = $this->getChartOptions($x)) {
                foreach ($chart_opts as $k => $v) {
                    if (!empty($v)) {
                        // use single-quoted attribute-value syntax for later JSON parsing in JavaScript
                        $output .= ' data-' . str_replace('_', '-', $k) . "='" . $v . "'";
                    }
                }
            }
            $output .= '></div>'; // .igsv-chart
        }

        return $output;
    }

    private function getChartOptions($atts) {
        $opts = array();
        foreach ($atts as $k => $v) {
            if (0 === strpos($k, 'chart_')) {
                $opts[$k] = $v;
            }
        }
        return $opts;
    }

    private function displayData($resp, $atts, $content) {
        $type = explode(';', $resp['headers']['content-type']);
        switch ($type[0]) {
            case 'text/html':
                $gid = ($atts['gid']) ? $atts['gid'] : 0;
                $r = $this->parseHtml($resp['body'], $gid);
                break;
            case 'text/csv':
            default:
                $r = $this->parseCsv($resp['body']);
            break;
        }
        return $this->dataToHtml($r, $atts, $content);
    }

    public function addQuickTagButton () {
        $screen = get_current_screen();
        if (wp_script_is('quicktags') && 'post' === $screen->base) {
?>
<script type="text/javascript">
jQuery(function () {
    var d = jQuery('#qt_content_igsv_sheet_dialog');
    d.dialog({
        'dialogClass'  : 'wp-dialog',
        'modal'        : true,
        'autoOpen'     : false,
        'closeOnEscape': true,
        'minWidth'     : 500,
        'buttons'      : {
            'add' : {
                'text'  : '<?php print esc_js(__('Add Spreadsheet', 'inline-gdocs-viewer'));?>',
                'class' : 'button-primary',
                'click' : function () {
                    var x = jQuery('#content').prop('selectionStart');
                    var cur_txt = jQuery('#content').val();
                    var new_txt = '[gdoc key="' + jQuery('#js-qt-igsv-sheet-key').val() + '"]';

                    jQuery('#content').val([cur_txt.slice(0, x), new_txt, cur_txt.slice(x)].join(''));

                    jQuery('#js-qt-igsv-sheet-key').val('');
                    jQuery(this).dialog('close');
                }
            }
        }
    });
    QTags.addButton(
        'igsv_sheet',
        'gdoc',
        function () {
            jQuery('#qt_content_igsv_sheet').on('click', function (e) {
                e.preventDefault();
                d.dialog('open');
            });
            jQuery('#qt_content_igsv_sheet').click();
        },
        '[/gdoc]',
        '',
        '<?php print esc_js(__('Inline Google Spreadsheet shortcode', 'inline-gdocs-viewer'));?>',
        130
    );
});
</script>
<div id="qt_content_igsv_sheet_dialog" title="<?php esc_attr_e('Insert inline Google Spreadsheet', 'inline-gdocs-viewer');?>">
    <p class="howto"><?php esc_html_e('Enter the key (web address) of your Google Spreadsheet', 'inline-gdocs-viewer');?></p>
    <div>
        <label>
            <span><?php esc_html_e('Key', 'inline-gdocs-viewer');?></span>
            <input style="width: 75%;" id="js-qt-igsv-sheet-key" placeholder="<?php esc_attr_e('paste your Spreadsheet URL here', 'inline-gdocs-viewer');?>" />
        </label>
    </div>
    <?php print $this->showDonationAppeal();?>
</div><!-- #qt_content_igsv_sheet_dialog -->
<?php
        }
    }

    private function registerContextualHelp () {
        $screen = get_current_screen();
        if ($screen->id !== 'post' ) { return; }

        $html = '<p>';
        $html .= sprintf(
            esc_html__('You can insert a Google Spreadsheet in this post. To do so, type %s[gdoc key="YOUR_SPREADSHEET_URL"]%s wherever you would like the spreadsheet to appear. Remember to replace YOUR_SPREADSHEET_URL with the web address of your Google Spreadsheet.', 'inline-gdocs-viewer'),
            '<kbd>', '</kbd>'
        );
        $html .= '</p>';
        $html .= '<p>';
        $html .= esc_html__('Only Google Spreadsheets that have been shared using either the "Public on the web" or "anyone with the link" options will be visible on this page.', 'inline-gdocs-viewer');
        $html .= '</p>';
        $html .= '<p>' . sprintf(
            esc_html__('You can also transform your data into an interactive chart by using the %1$schart%2$s attribute. Supported chart types are Area, Bar, Bubble, Candlestick, Column, Combo, Histogram, Line, Pie, Scatter, and Stepped. For instance, to make a Pie chart, type %1$s[gdoc key="YOUR_SPREADSHEET_URL" chart="Pie"]%2$s. Customize your chart with your own choice of colors by supplying a space-separated list of color values with the %1$schart_colors%2$s attribute, like %1$schart_colors="red green"%2$s. Additional options depend on the chart you use.' ,'inline-gdocs-viewer'),
            '<kbd>', '</kbd>'
        ) . '</p>';
        $html .= '<p>' . sprintf(
            esc_html__('Refer to the %1$sshortcode attribute documentation%3$s for a complete list of shortcode attributes, and the %2$sGoogle Chart API documentation%3$s for more information about each option.' ,'inline-gdocs-viewer'),
            '<a href="https://wordpress.org/plugins/inline-google-spreadsheet-viewer/other_notes/" target="_blank">',
            '<a href="https://developers.google.com/chart/interactive/docs/gallery" target="_blank">', '</a>'
        ) . '</p>';
        $html .= '<p>';
        $html .= sprintf(
            esc_html__('If you are having trouble getting your Spreadsheet to show up on your website, you can %sget help from the plugin support forum%s. Consider searching the support forum to see if your question has already been answered before posting a new thread.', 'inline-gdocs-viewer'),
            '<a href="https://wordpress.org/support/plugin/inline-google-spreadsheet-viewer/">', '</a>'
        );
        $html .= '</p>';
        ob_start();
        $this->showDonationAppeal();
        $x = ob_get_contents();
        ob_end_clean();
        $html .= $x;
        $screen->add_help_tab(array(
            'id' => $this->shortcode . '-' . $screen->base . '-help',
            'title' => __('Inserting a Google Spreadsheet', 'inline-gdocs-viewer'),
            'content' => $html
        ));
    }

    private function showDonationAppeal () {
?>
<div class="donation-appeal">
    <p style="text-align: center; font-style: italic; margin: 1em 3em;"><?php print sprintf(
esc_html__('Inline Google Spreadsheet Viewer is provided as free software, but sadly grocery stores do not offer free food. If you like this plugin, please consider %1$s to its %2$s. &hearts; Thank you!', 'inline-gdocs-viewer'),
'<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=meitarm%40gmail%2ecom&lc=US&amp;item_name=Inline%20Google%20Spreadsheet%20Viewer%20WordPress%20Plugin&amp;item_number=inline%2dgdocs%2dviewer&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted">' . esc_html__('making a donation', 'inline-gdocs-viewer') . '</a>',
'<a href="http://Cyberbusking.org/">' . esc_html__('houseless, jobless, nomadic developer', 'inline-gdocs-viewer') . '</a>'
);?></p>
</div>
<?php
    }
}

$inline_gdoc_viewer = new InlineGoogleSpreadsheetViewerPlugin();
