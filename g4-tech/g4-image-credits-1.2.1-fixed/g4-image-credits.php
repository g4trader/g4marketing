<?php
/**
 * Plugin Name: G4 Créditos de Imagem
 * Description: Exibe créditos/legendas de imagens (a partir da Legenda da mídia) e gerencia a posição da imagem destacada dentro do conteúdo. Compatível com Astra.
 * Version: 1.2.1
 * Author: G4 Marketing
 * License: GPL-2.0+
 * Text Domain: g4-image-credits
 */

if (!defined('ABSPATH')) { exit; }

class G4_Image_Credits {
    const OPT_KEY = 'g4ic_options';
    const VER = '1.2.1';

    private static $instance = null;
    private $printed_featured = false;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate_defaults'));

        // Frontend (credits)
        add_filter('post_thumbnail_html', array($this, 'filter_featured_thumbnail'), 10, 5);
        add_filter('render_block', array($this, 'filter_core_image_block'), 10, 2);

        // Featured placement management
        add_filter('the_content', array($this, 'maybe_manage_featured_in_content'), 12);

        // Styles
        add_action('wp_head', array($this, 'inline_css'));

        // Astra support hooks (only when letting the theme handle featured)
        add_action('astra_entry_after_featured_image', array($this, 'astra_featured_after'), 20);
        add_action('astra_single_header_after', array($this, 'astra_featured_after_legacy'), 20);

        // Admin
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_settings_page'));
            add_action('admin_init', array($this, 'register_settings'));
        }
    }

    /* ---------- Options ---------- */
    public static function defaults() {
        return array(
            // Credits
            'enable_featured' => 1,
            'enable_block'    => 1,
            'single_only'     => 1,
            'prefix'          => 'Crédito: ',
            'align'           => 'center', // center|left|right (só para o texto do crédito)
            'font_size'       => '12',     // px
            'fallback_mode'   => 'attachment', // none | attachment | custom
            'fallback_custom' => '',

            // Placement manager
            'manage_featured'     => 'theme', // theme|before|after_first|after_n|after_content
            'featured_n'          => 2,
            'hide_theme_featured' => 1,

            // Astra legacy
            'astra_legacy_hook'   => 0,

            // Safety
            'force_in_content'    => 1,

            // NEW 1.2.1: Centering options
            'center_featured'         => 0, // centraliza a imagem destacada
            'center_content_images'   => 0, // centraliza imagens do bloco core/image sem alinhamento explícito
        );
    }

    public function get_opts() {
        $opts = get_option(self::OPT_KEY, array());
        if (isset($opts['use_fallback'])) { // migração antiga
            $opts['fallback_mode'] = $opts['use_fallback'] ? 'attachment' : 'none';
            unset($opts['use_fallback']);
        }
        $opts = wp_parse_args($opts, self::defaults());
        return $opts;
    }
    public function get_opt($key) {
        $opts = $this->get_opts();
        return isset($opts[$key]) ? $opts[$key] : self::defaults()[$key];
    }
    public function activate_defaults() {
        if (!get_option(self::OPT_KEY)) {
            add_option(self::OPT_KEY, self::defaults());
        }
    }

    /* ---------- Helpers ---------- */
    private function current_context_allows() {
        if (!$this->get_opt('single_only')) return true;
        return is_singular();
    }
    private function get_credit_text($attachment_id) {
        if (!$attachment_id) return '';

        $caption = wp_get_attachment_caption($attachment_id);
        if (!empty($caption)) return $caption;

        $mode = $this->get_opt('fallback_mode');
        if ($mode === 'custom') {
            $custom = (string) $this->get_opt('fallback_custom');
            return $custom ? $custom : '';
        }
        if ($mode === 'attachment') {
            $post = get_post($attachment_id);
            if ($post) {
                if (!empty($post->post_content)) return wp_strip_all_tags($post->post_content); // Descrição
                if (!empty($post->post_title))   return wp_strip_all_tags($post->post_title);   // Título
            }
        }
        return '';
    }
    private function ensure_prefix($text) {
        $prefix = (string) $this->get_opt('prefix');
        if ($prefix && stripos($text, $prefix) !== 0) {
            return $prefix . $text;
        }
        return $text;
    }
    private function credit_html($text, $wrapper = 'span') {
        $text = $this->ensure_prefix($text);
        $tag  = $wrapper === 'div' ? 'div' : 'span';
        return '<'.$tag.' class="g4-img-credit" role="note" aria-label="Crédito da imagem">'.esc_html($text).'</'.$tag.'>';
    }
    private function wrap_figure_with_caption($img_html, $caption_text, $extra_class = '') {
        if ($caption_text === '') return $img_html;
        $figcaption = '<figcaption class="g4-img-credit">'.esc_html($this->ensure_prefix($caption_text)).'</figcaption>';
        return '<figure class="g4ic-figure '.$extra_class.'">'.$img_html.$figcaption.'</figure>';
    }
    private function build_featured_markup($thumb_id, $size = 'full') {
        if (!$thumb_id) return '';
        $img = wp_get_attachment_image($thumb_id, $size, false, array('class' => 'wp-post-image'));
        $credit = $this->get_credit_text($thumb_id);
        if ($credit) {
            return $this->wrap_figure_with_caption($img, $credit, 'g4ic-featured');
        }
        return $img;
    }
    private function content_has_featured($content, $thumb_id) {
        if (strpos($content, 'wp-post-image') !== false) return true;
        if ($thumb_id && strpos($content, 'wp-image-' . intval($thumb_id)) !== false) return true;
        return false;
    }
    private function inject_after_nth_paragraph($content, $insertion, $n) {
        $closing_p = '</p>';
        $parts = explode($closing_p, $content);
        $total = count($parts);
        if ($total < 2) return $content . $insertion; // sem <p>, insere no fim
        $n = max(1, intval($n));
        $inserted = false;
        $out = '';
        for ($i = 0; $i < $total; $i++) {
            $out .= $parts[$i];
            if ($i < $total - 1) {
                $out .= $closing_p;
                if (!$inserted && ($i + 1) == $n) {
                    $out .= $insertion;
                    $inserted = true;
                }
            }
        }
        if (!$inserted) $out .= $insertion;
        return $out;
    }

    /* ---------- Featured credits when theme prints it ---------- */
    public function filter_featured_thumbnail($html, $post_id, $thumb_id, $size, $attr) {
        if ($this->get_opt('manage_featured') !== 'theme') {
            if ($this->get_opt('hide_theme_featured')) {
                return ''; // impede impressão do tema
            } else {
                return $html;
            }
        }

        if (!$this->get_opt('enable_featured')) return $html;
        if (!$this->current_context_allows())   return $html;
        if (!$thumb_id) return $html;

        $credit = $this->get_credit_text($thumb_id);
        if (!$credit) return $html;

        if (stripos($html, '<figure') !== false) {
            $caption = '<figcaption class="g4-img-credit">'.esc_html($this->ensure_prefix($credit)).'</figcaption>';
            $html = preg_replace('#</figure>#i', $caption.'</figure>', $html, 1);
        } else {
            $html .= $this->credit_html($credit, 'span');
        }
        $this->printed_featured = true;
        return $html;
    }

    public function astra_featured_after() {
        if ($this->get_opt('manage_featured') !== 'theme') return;
        if ($this->printed_featured) return;
        if (!$this->get_opt('enable_featured') || !$this->current_context_allows()) return;
        $id = get_post_thumbnail_id(get_the_ID());
        if (!$id) return;
        $credit = $this->get_credit_text($id);
        if (!$credit) return;
        echo $this->credit_html($credit, 'div');
        $this->printed_featured = true;
    }
    public function astra_featured_after_legacy() {
        if (!$this->get_opt('astra_legacy_hook')) return;
        $this->astra_featured_after();
    }

    /* ---------- Core block images ---------- */
    public function filter_core_image_block($content, $block) {
        if (!$this->get_opt('enable_block')) return $content;
        if (!$this->current_context_allows())  return $content;
        if (!is_array($block) || empty($block['blockName'])) return $content;
        if ($block['blockName'] !== 'core/image') return $content;

        if (strpos($content, '<figcaption') !== false) return $content;

        $id = isset($block['attrs']['id']) ? intval($block['attrs']['id']) : 0;
        if (!$id) return $content;

        $credit = $this->get_credit_text($id);
        if (!$credit) return $content;

        $caption = '<figcaption class="g4-img-credit">'.esc_html($this->ensure_prefix($credit)).'</figcaption>';
        return preg_replace('#</figure>\s*$#', $caption.'</figure>', $content, 1);
    }

    /* ---------- Featured placement manager (inject in content) ---------- */
    public function maybe_manage_featured_in_content($content) {
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;
        $mode = $this->get_opt('manage_featured');
        if ($mode === 'theme') {
            if ($this->get_opt('force_in_content') && $this->get_opt('enable_featured')) {
                $thumb_id = get_post_thumbnail_id(get_the_ID());
                if ($thumb_id && strpos($content, 'wp-post-image') !== false && strpos($content, 'g4-img-credit') === false) {
                    $credit = $this->get_credit_text($thumb_id);
                    if ($credit) {
                        $credit_html = $this->credit_html($credit, 'div');
                        $content = preg_replace('#(<img[^>]*class="[^"]*wp-post-image[^"]*"[^>]*>)#i', '$1'.$credit_html, $content, 1);
                    }
                }
            }
            return $content;
        }

        $thumb_id = get_post_thumbnail_id(get_the_ID());
        if (!$thumb_id) return $content;
        if ($this->content_has_featured($content, $thumb_id)) return $content;

        $markup = $this->build_featured_markup($thumb_id);
        if (!$markup) return $content;

        switch ($mode) {
            case 'before':
                return $markup . $content;
            case 'after_first':
                return $this->inject_after_nth_paragraph($content, $markup, 1);
            case 'after_n':
                $n = intval($this->get_opt('featured_n'));
                if ($n <= 0) $n = 2;
                return $this->inject_after_nth_paragraph($content, $markup, $n);
            case 'after_content':
            default:
                return $content . $markup;
        }
    }

    /* ---------- CSS ---------- */
    public function inline_css() {
        $align = esc_attr($this->get_opt('align'));
        $font  = intval($this->get_opt('font_size'));
        if ($font <= 0) $font = 12;

        $css  = '.g4-img-credit{display:block;margin-top:6px;font-size:'.$font.'px;line-height:1.35;color:#666;text-align:'.$align.';}';
        if ($this->get_opt('manage_featured') !== 'theme' && $this->get_opt('hide_theme_featured')) {
            $css .= '.ast-single-post-featured-section{display:none!important;}';
        }

        // NEW 1.2.1: Centering rules
        if ( $this->get_opt('center_featured') ) {
            $css .= '.g4ic-featured img{display:block;margin-left:auto;margin-right:auto;}';
            $css .= '.ast-single-post-featured-section .wp-post-image{display:block;margin-left:auto;margin-right:auto;}';
        }
        if ( $this->get_opt('center_content_images') ) {
            $css .= '.entry-content .wp-block-image:not(.alignleft):not(.alignright){text-align:center;}';
            $css .= '.entry-content .wp-block-image:not(.alignleft):not(.alignright) img{display:block;margin-left:auto;margin-right:auto;}';
            $css .= '.entry-content figure.g4ic-figure img{display:block;margin-left:auto;margin-right:auto;}';
        }

        echo '<style id="g4ic-inline-css">'.$css.'</style>';
    }

    /* ---------- Admin ---------- */
    public function add_settings_page() {
        add_options_page(
            __('G4 Créditos de Imagem', 'g4-image-credits'),
            __('G4 Créditos', 'g4-image-credits'),
            'manage_options',
            'g4-image-credits',
            array($this, 'render_settings_page')
        );
    }
    public function register_settings() {
        register_setting('g4ic_group', self::OPT_KEY, array($this, 'sanitize_options'));

        add_settings_section('g4ic_main', __('Configurações', 'g4-image-credits'), function(){
            echo '<p>'.esc_html__('Controle os créditos e a posição da imagem destacada.', 'g4-image-credits').'</p>';
        }, 'g4-image-credits');

        // Créditos
        $this->add_checkbox_field('enable_featured', __('Imagem destacada', 'g4-image-credits'), __('Exibir crédito da imagem destacada', 'g4-image-credits'));
        $this->add_checkbox_field('enable_block', __('Blocos de imagem', 'g4-image-credits'), __('Adicionar crédito quando o bloco core/image não tiver legenda', 'g4-image-credits'));
        $this->add_checkbox_field('single_only', __('Apenas em páginas singulares', 'g4-image-credits'), __('Exibir apenas em posts/páginas (evita listas/arquivos)', 'g4-image-credits'));

        add_settings_field('prefix', __('Prefixo do crédito', 'g4-image-credits'), array($this, 'field_text'), 'g4-image-credits', 'g4ic_main', array('key'=>'prefix', 'placeholder'=>'Crédito: '));
        add_settings_field('align', __('Alinhamento (texto do crédito)', 'g4-image-credits'), array($this, 'field_select'), 'g4-image-credits', 'g4ic_main', array('key'=>'align', 'options'=>array('left'=>'Esquerda','center'=>'Centralizado','right'=>'Direita')));
        add_settings_field('font_size', __('Tamanho da fonte (px)', 'g4-image-credits'), array($this, 'field_number'), 'g4-image-credits', 'g4ic_main', array('key'=>'font_size', 'min'=>10, 'max'=>18, 'step'=>1));

        add_settings_field('fallback_mode', __('Fallback (quando a Legenda estiver vazia)', 'g4-image-credits'), array($this, 'field_fallback_mode'), 'g4-image-credits', 'g4ic_main');
        add_settings_field('fallback_custom', __('Texto fixo do fallback', 'g4-image-credits'), array($this, 'field_text'), 'g4-image-credits', 'g4ic_main', array('key'=>'fallback_custom', 'placeholder'=>'Ex.: Crédito: Assessoria de Comunicação'));

        // Gerenciamento da destacada
        add_settings_field('manage_featured', __('Posição da imagem destacada', 'g4-image-credits'), array($this, 'field_manage_featured'), 'g4-image-credits', 'g4ic_main');
        add_settings_field('featured_n', __('Após N parágrafos', 'g4-image-credits'), array($this, 'field_number'), 'g4-image-credits', 'g4ic_main', array('key'=>'featured_n', 'min'=>1, 'max'=>20, 'step'=>1));
        $this->add_checkbox_field('hide_theme_featured', __('Ocultar destacada do tema', 'g4-image-credits'), __('Quando gerenciado pelo plugin, ocultar a destacada que o tema imprime (Astra header etc.)', 'g4-image-credits'));

        // Centralização
        $this->add_checkbox_field('center_featured', __('Centralizar imagem destacada', 'g4-image-credits'), __('Força a imagem destacada a ficar centralizada', 'g4-image-credits'));
        $this->add_checkbox_field('center_content_images', __('Centralizar imagens do conteúdo', 'g4-image-credits'), __('Centraliza blocos de imagem sem alinhamento explícito (não afeta alignleft/alignright)', 'g4-image-credits'));

        // Astra legacy
        $this->add_checkbox_field('astra_legacy_hook', __('Compatibilidade Astra (legado)', 'g4-image-credits'), __('Usar hook legado do Astra (coloca crédito próximo à imagem do header em alguns layouts). Use apenas se necessário.', 'g4-image-credits'));

        // Segurança extra
        $this->add_checkbox_field('force_in_content', __('Forçar crédito no conteúdo', 'g4-image-credits'), __('Se a destacada estiver dentro do conteúdo e sem crédito, inserir o crédito logo abaixo da imagem', 'g4-image-credits'));
    }
    private function add_checkbox_field($key, $title, $label) {
        add_settings_field($key, $title, array($this, 'field_checkbox'), 'g4-image-credits', 'g4ic_main', array('key'=>$key, 'label'=>$label));
    }
    public function sanitize_options($input) {
        $out = $this->get_opts();

        $out['enable_featured'] = !empty($input['enable_featured']) ? 1 : 0;
        $out['enable_block']    = !empty($input['enable_block']) ? 1 : 0;
        $out['single_only']     = !empty($input['single_only']) ? 1 : 0;

        $out['prefix']          = isset($input['prefix']) ? sanitize_text_field($input['prefix']) : $out['prefix'];
        $aligns = array('left','center','right');
        $out['align']           = (isset($input['align']) && in_array($input['align'], $aligns, true)) ? $input['align'] : $out['align'];
        $out['font_size']       = isset($input['font_size']) ? max(8, min(24, intval($input['font_size']))) : $out['font_size'];

        $valid_modes = array('none','attachment','custom');
        $out['fallback_mode']   = (isset($input['fallback_mode']) && in_array($input['fallback_mode'], $valid_modes, true)) ? $input['fallback_mode'] : $out['fallback_mode'];
        $out['fallback_custom'] = isset($input['fallback_custom']) ? sanitize_text_field($input['fallback_custom']) : $out['fallback_custom'];

        $valid_manage = array('theme','before','after_first','after_n','after_content');
        $out['manage_featured'] = (isset($input['manage_featured']) && in_array($input['manage_featured'], $valid_manage, true)) ? $input['manage_featured'] : $out['manage_featured'];
        $out['featured_n']      = isset($input['featured_n']) ? max(1, min(50, intval($input['featured_n']))) : $out['featured_n'];
        $out['hide_theme_featured'] = !empty($input['hide_theme_featured']) ? 1 : 0;

        $out['center_featured'] = !empty($input['center_featured']) ? 1 : 0;
        $out['center_content_images'] = !empty($input['center_content_images']) ? 1 : 0;

        $out['astra_legacy_hook'] = !empty($input['astra_legacy_hook']) ? 1 : 0;
        $out['force_in_content']  = !empty($input['force_in_content']) ? 1 : 0;

        return $out;
    }
    public function field_checkbox($args) {
        $val = $this->get_opt($args['key']) ? 'checked' : '';
        $label = isset($args['label']) ? $args['label'] : '';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_KEY).'['.esc_attr($args['key']).']" value="1" '.$val.'> '.esc_html($label).'</label>';
    }
    public function field_text($args) {
        $val = esc_attr($this->get_opt($args['key']));
        $ph  = isset($args['placeholder']) ? esc_attr($args['placeholder']) : '';
        echo '<input type="text" class="regular-text" name="'.esc_attr(self::OPT_KEY).'['.esc_attr($args['key']).']" value="'.$val.'" placeholder="'.$ph.'">';
    }
    public function field_select($args) {
        $val = $this->get_opt($args['key']);
        echo '<select name="'.esc_attr(self::OPT_KEY).'['.esc_attr($args['key']).']">';
        foreach ($args['options'] as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($val, $k, false).'>'.esc_html($label).'</option>';
        }
        echo '</select>';
    }
    public function field_number($args) {
        $val = intval($this->get_opt($args['key']));
        $min = isset($args['min']) ? intval($args['min']) : 0;
        $max = isset($args['max']) ? intval($args['max']) : 999;
        $step= isset($args['step']) ? intval($args['step']) : 1;
        echo '<input type="number" min="'.$min.'" max="'.$max.'" step="'.$step.'" name="'.esc_attr(self::OPT_KEY).'['.esc_attr($args['key']).']" value="'.$val.'">';
    }
    public function field_fallback_mode() {
        $mode = $this->get_opt('fallback_mode');
        $name = esc_attr(self::OPT_KEY).'[fallback_mode]';
        ?>
        <fieldset>
            <label><input type="radio" name="<?php echo $name; ?>" value="none" <?php checked($mode, 'none'); ?>> <?php esc_html_e('Sem fallback (só Legenda)', 'g4-image-credits'); ?></label><br>
            <label><input type="radio" name="<?php echo $name; ?>" value="attachment" <?php checked($mode, 'attachment'); ?>> <?php esc_html_e('Legenda → Descrição → Título (anexo)', 'g4-image-credits'); ?></label><br>
            <label><input type="radio" name="<?php echo $name; ?>" value="custom" <?php checked($mode, 'custom'); ?>> <?php esc_html_e('Usar texto fixo configurado abaixo', 'g4-image-credits'); ?></label>
        </fieldset>
        <?php
    }
    public function field_manage_featured() {
        $val = $this->get_opt('manage_featured');
        $name = esc_attr(self::OPT_KEY).'[manage_featured]';
        ?>
        <select name="<?php echo $name; ?>">
            <option value="theme"        <?php selected($val,'theme'); ?>>Deixar o tema decidir (padrão)</option>
            <option value="before"       <?php selected($val,'before'); ?>>Antes do conteúdo</option>
            <option value="after_first"  <?php selected($val,'after_first'); ?>>Após o 1º parágrafo</option>
            <option value="after_n"      <?php selected($val,'after_n'); ?>>Após N parágrafos</option>
            <option value="after_content"<?php selected($val,'after_content'); ?>>No final do conteúdo</option>
        </select>
        <?php
    }
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('G4 Créditos de Imagem', 'g4-image-credits'); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields('g4ic_group');
                    do_settings_sections('g4-image-credits');
                    submit_button();
                ?>
            </form>
            <p><em><?php esc_html_e('Preencha a "Legenda" do anexo em Mídia › Biblioteca. O plugin usa esse campo como crédito.', 'g4-image-credits'); ?></em></p>
        </div>
        <?php
    }
}

G4_Image_Credits::instance();
