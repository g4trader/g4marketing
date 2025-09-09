<?php
/**
 * Plugin Name: G4 VideoJS IMA
 * Description: Player Video.js com IMA pre-roll, placeholder anti-CLS, loop opcional e página de configurações. Shortcode: [videojs_ima].
 * Version: 1.6.1
 * Author: G4 Marketing
 */
if (!defined('ABSPATH')) exit;

class G4_VideoJS_IMA_Admin_161 {
  private $opt_key = 'vjs_ima_options';

  public function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    register_activation_hook(__FILE__, [$this, 'activate_defaults']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_shortcode('videojs_ima', [$this, 'shortcode']);
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'settings_link']);
  }

  /* ===== Admin ===== */
  public function admin_menu() {
    add_options_page(
      __('G4 VideoJS IMA', 'g4-vjs-ima'),
      __('G4 VideoJS IMA', 'g4-vjs-ima'),
      'manage_options',
      'g4-vjs-ima',
      [$this, 'settings_page']
    );
  }

  public function settings_link($links) {
    $url = admin_url('options-general.php?page=g4-vjs-ima');
    $links[] = '<a href="' . esc_url($url) . '">'.esc_html__('Settings', 'g4-vjs-ima').'</a>';
    return $links;
  }

  public function activate_defaults() {
    $defaults = $this->default_options();
    $existing = get_option($this->opt_key);
    if (!$existing || !is_array($existing)) {
      update_option($this->opt_key, $defaults);
    } else {
      update_option($this->opt_key, array_merge($defaults, $existing));
    }
  }

  private function default_options() {
    return [
      'src'        => '',
      'poster'     => '',
      'vast'       => '',
      'width'      => '100%',
      'aspect'     => '56.25%', // 16:9
      'autoplay'   => 'true',
      'muted'      => 'true',
      'controls'   => 'false',
      'preload'    => 'metadata',
      'npa'        => '0',
      'fallback'   => 'placeholder', // placeholder|content
      'loopads'    => 'false',
      'loop_delay' => '5',
      'loop_max'   => '0',
      'locale'     => 'pt'
    ];
  }

  public function register_settings() {
    register_setting('g4_vjs_ima_group', $this->opt_key, [$this, 'sanitize_options']);

    add_settings_section('g4_vjs_ima_main', __('Parâmetros padrão do player/ads', 'g4-vjs-ima'), function(){
      echo '<p>'.esc_html__('Defina os valores padrão. O shortcode pode sobrescrever qualquer campo.', 'g4-vjs-ima').'</p>';
    }, 'g4-vjs-ima');

    $fields = [
      'src'        => ['URL do vídeo curto (1s)', 'text'],
      'poster'     => ['URL do poster', 'text'],
      'vast'       => ['URL da tag VAST (GAM)', 'text'],
      'width'      => ['Largura (ex: 100% ou 640px)', 'text'],
      'aspect'     => ['Aspect ratio em % (16:9 = 56.25%)', 'text'],
      'autoplay'   => ['Autoplay', 'select', ['true'=>'true','false'=>'false']],
      'muted'      => ['Muted', 'select', ['true'=>'true','false'=>'false']],
      'controls'   => ['Controles', 'select', ['true'=>'true','false'=>'false']],
      'preload'    => ['Preload', 'select', ['auto'=>'auto','metadata'=>'metadata','none'=>'none']],
      'npa'        => ['NPA (0/1)', 'select', ['0'=>'0','1'=>'1']],
      'fallback'   => ['Fallback quando sem fill', 'select', ['placeholder'=>'placeholder','content'=>'content']],
      'loopads'    => ['Loop de anúncios', 'select', ['false'=>'false','true'=>'true']],
      'loop_delay' => ['Intervalo do loop (s)', 'number'],
      'loop_max'   => ['Máx. ciclos (0=infinito)', 'number'],
      'locale'     => ['Locale IMA (ex: pt, en)', 'text'],
    ];

    foreach ($fields as $key => $meta) {
      add_settings_field($key, esc_html($meta[0]), function() use ($key, $meta) {
        $opts = get_option($this->opt_key, $this->default_options());
        $val = isset($opts[$key]) ? $opts[$key] : '';
        $type = isset($meta[1]) ? $meta[1] : 'text';
        if ($type === 'select') {
          $choices = isset($meta[2]) ? $meta[2] : [];
          echo '<select name="'.$this->opt_key.'['.esc_attr($key).']">';
          foreach ($choices as $k => $label) {
            echo '<option value="'.esc_attr($k).'" '.selected($val, $k, false).'>'.esc_html($label).'</option>';
          }
          echo '</select>';
        } else {
          $input_type = ($type === 'number') ? 'number' : 'text';
          $step = ($type === 'number') ? ' step="1"' : '';
          echo '<input type="'.$input_type.'" name="'.$this->opt_key.'['.esc_attr($key).']" value="'.esc_attr($val).'" class="regular-text"'.$step.' />';
        }
      }, 'g4-vjs-ima', 'g4_vjs_ima_main');
    }
  }

  public function sanitize_options($opts) {
    $d = $this->default_options();
    $clean = [];
    $clean['src']        = esc_url_raw($opts['src'] ?? $d['src']);
    $clean['poster']     = esc_url_raw($opts['poster'] ?? $d['poster']);
    $clean['vast']       = esc_url_raw($opts['vast'] ?? $d['vast']);
    $clean['width']      = sanitize_text_field($opts['width'] ?? $d['width']);
    $clean['aspect']     = sanitize_text_field($opts['aspect'] ?? $d['aspect']);
    $clean['autoplay']   = in_array(($opts['autoplay'] ?? $d['autoplay']), ['true','false'], true) ? $opts['autoplay'] : $d['autoplay'];
    $clean['muted']      = in_array(($opts['muted'] ?? $d['muted']), ['true','false'], true) ? $opts['muted'] : $d['muted'];
    $clean['controls']   = in_array(($opts['controls'] ?? $d['controls']), ['true','false'], true) ? $opts['controls'] : $d['controls'];
    $clean['preload']    = in_array(($opts['preload'] ?? $d['preload']), ['auto','metadata','none'], true) ? $opts['preload'] : $d['preload'];
    $clean['npa']        = in_array(($opts['npa'] ?? $d['npa']), ['0','1'], true) ? $opts['npa'] : $d['npa'];
    $clean['fallback']   = in_array(($opts['fallback'] ?? $d['fallback']), ['placeholder','content'], true) ? $opts['fallback'] : $d['fallback'];
    $clean['loopads']    = in_array(($opts['loopads'] ?? $d['loopads']), ['true','false'], true) ? $opts['loopads'] : $d['loopads'];
    $clean['loop_delay'] = strval(intval($opts['loop_delay'] ?? $d['loop_delay']));
    $clean['loop_max']   = strval(intval($opts['loop_max'] ?? $d['loop_max']));
    $clean['locale']     = preg_replace('/[^a-zA-Z\-]/', '', ($opts['locale'] ?? $d['locale']));
    return $clean;
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('G4 VideoJS IMA — Configurações', 'g4-vjs-ima'); ?></h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('g4_vjs_ima_group');
          do_settings_sections('g4-vjs-ima');
          submit_button();
        ?>
      </form>
      <hr/>
      <p><strong>Shortcode básico:</strong> <code>[videojs_ima]</code></p>
      <p>Qualquer parâmetro pode ser sobrescrito no shortcode (ex.: <code>[videojs_ima controls="true" loopads="true"]</code>).</p>
    </div>
    <?php
  }

  /* ===== Frontend ===== */
  public function enqueue_assets() {
    // Video.js
    wp_enqueue_style('videojs-css', 'https://vjs.zencdn.net/8.16.1/video-js.css', [], null);
    wp_enqueue_script('videojs-js', 'https://vjs.zencdn.net/8.16.1/video.min.js', [], null, true);

    // IMA + plugins
    wp_enqueue_script('ima-sdk', 'https://imasdk.googleapis.com/js/sdkloader/ima3.js', [], null, true);
    wp_enqueue_script('vjs-ads', 'https://cdn.jsdelivr.net/npm/videojs-contrib-ads@6.9.0/dist/videojs-contrib-ads.min.js', ['videojs-js'], null, true);
    wp_enqueue_script('vjs-ima', 'https://cdn.jsdelivr.net/npm/videojs-ima@1.10.0/dist/videojs.ima.min.js', ['videojs-js','vjs-ads','ima-sdk'], null, true);

    // CSS inline (placeholder anti-CLS + anti-flash do IMA)
    $css = "
      .vjs-frame { max-width:100%; }
      .vjs-aspect { position: relative; width: 100%; }
      .vjs-aspect > .vjs-placeholder,
      .vjs-aspect > .vjs-slot { position:absolute; inset:0; }
      .vjs-placeholder img { width:100%; height:100%; object-fit:cover; display:block; }
      .vjs-slot { opacity:0; pointer-events:none; transition:opacity .15s ease; }
      .vjs-slot.vjs-visible { opacity:1; pointer-events:auto; }
      .video-js { position:relative; width:100%; height:100%; background:transparent!important; overflow:hidden; }
      .vjs-poster { background-size:cover; }
      /* Evita flash do contêiner IMA fora do player */
      .ima-ad-container,
      .vjs-ima .ima-ad-container,
      .vjs-ima .vjs-ima-ad-container,
      div[id^='ima-ad-container'],
      div[id*='ima-ad-container'] {
        position:fixed!important;
        left:-99999px!important; top:-99999px!important;
        width:1px!important; height:1px!important;
        opacity:0!important; pointer-events:none!important;
        background:transparent!important;
      }
      .video-js .ima-ad-container.ima-visible {
        position:absolute!important; left:0!important; top:0!important;
        width:100%!important; height:100%!important;
        opacity:1!important; pointer-events:auto!important; z-index:2;
      }
    ";
    wp_add_inline_style('videojs-css', $css);

    // JS init
    wp_enqueue_script('videojs-ima-gam-init', plugin_dir_url(__FILE__) . 'videojs-ima-gam-init.js', ['videojs-js','vjs-ads','vjs-ima'], '1.6.1', true);
  }

  private function maybe_append_description_url($vast_url) {
    if (empty($vast_url)) return $vast_url;
    if (strpos($vast_url, 'description_url=') !== false) return $vast_url;
    $sep = (strpos($vast_url,'?') !== false) ? '&' : '?';
    $desc = rawurlencode(get_permalink());
    return $vast_url . $sep . 'description_url=' . $desc;
  }

  private function get_opts() {
    $opts = get_option($this->opt_key, $this->default_options());
    if (!is_array($opts)) $opts = $this->default_options();
    return array_merge($this->default_options(), $opts);
  }

  public function shortcode($atts) {
    $o = $this->get_opts();
    $a = shortcode_atts([
      'src'         => $o['src'],
      'poster'      => $o['poster'],
      'vast'        => $o['vast'],
      'width'       => $o['width'],
      'aspect'      => $o['aspect'],
      'autoplay'    => $o['autoplay'],
      'muted'       => $o['muted'],
      'controls'    => $o['controls'],
      'preload'     => $o['preload'],
      'npa'         => $o['npa'],
      'fallback'    => $o['fallback'],
      'loopads'     => $o['loopads'],
      'loop_delay'  => $o['loop_delay'],
      'loop_max'    => $o['loop_max'],
      'locale'      => $o['locale'],
    ], $atts);

    if (empty($a['src']))  return '<p style="color:red">[videojs_ima] ERRO: defina o atributo src (vídeo curto de 1s) nas Configurações ou no shortcode.</p>';
    if (empty($a['vast'])) return '<p style="color:red">[videojs_ima] ERRO: defina o atributo vast (tag VAST do GAM) nas Configurações ou no shortcode.</p>';

    $vast = $this->maybe_append_description_url(esc_url_raw($a['vast']));
    $sep  = (strpos($vast,'?') !== false) ? '&' : '?';
    if (strpos($vast, 'npa=') === false) { $vast .= $sep . 'npa=' . ($a['npa'] === '1' ? '1' : '0'); }

    $id = 'vjs_' . wp_generate_uuid4();

    ob_start(); ?>
      <div class="vjs-frame" style="width: <?php echo esc_attr($a['width']); ?>;">
        <div class="vjs-aspect" style="padding-top: <?php echo esc_attr($a['aspect']); ?>;">
          <div class="vjs-placeholder">
            <?php if (!empty($a['poster'])): ?>
              <img src="<?php echo esc_url($a['poster']); ?>" alt="" loading="lazy" decoding="async"/>
            <?php else: ?>
              <div style="width:100%;height:100%;background:#000;"></div>
            <?php endif; ?>
          </div>

          <div class="vjs-slot">
            <video
              id="<?php echo esc_attr($id); ?>"
              class="video-js vjs-default-skin vjs-big-play-centered"
              <?php if ($a['controls']==='true') echo 'controls'; ?>
              preload="<?php echo esc_attr($a['preload']); ?>"
              <?php if ($a['autoplay']==='true') echo 'autoplay'; ?>
              <?php if ($a['muted']==='true') echo 'muted playsinline'; ?>
              poster="<?php echo esc_url($a['poster']); ?>"
              data-vast="<?php echo esc_url($vast); ?>"
              data-npa="<?php echo esc_attr($a['npa']); ?>"
              data-fallback="<?php echo esc_attr($a['fallback']); ?>"
              data-loopads="<?php echo esc_attr($a['loopads']); ?>"
              data-loop-delay="<?php echo esc_attr($a['loop_delay']); ?>"
              data-loop-max="<?php echo esc_attr($a['loop_max']); ?>"
              data-locale="<?php echo esc_attr($a['locale']); ?>"
              style="width:100%;height:100%"
            >
              <source src="<?php echo esc_url($a['src']); ?>" type="video/mp4"/>
            </video>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }
}
new G4_VideoJS_IMA_Admin_161();
