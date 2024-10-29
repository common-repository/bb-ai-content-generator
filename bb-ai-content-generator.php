<?php
/*
Plugin Name: BB AI Content Generator
Description: Simplify writing in WordPress with BB AI Content Generator, leveraging OpenAI to turn your topics into complete articles.
Version: 1.3
Author: BBSEO Ventures
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

defined('ABSPATH') or die('No script kiddies please!');


// Şifreleme anahtarını oluştur veya al
function bb_ai_content_generator_get_encryption_key() {
    $key = get_option('bb_ai_content_generator_encryption_key');
    if (!$key) {
        // 256-bit (32 bytes) anahtar oluştur
        $key = openssl_random_pseudo_bytes(32);
        update_option('bb_ai_content_generator_encryption_key', base64_encode($key));
    } else {
        $key = base64_decode($key);
    }
    return $key;
}

// API anahtarını şifrele
function bb_ai_content_generator_encrypt_api_key($api_key) {
    $key = bb_ai_content_generator_get_encryption_key();
    $method = 'AES-256-CBC';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
    $encrypted = openssl_encrypt($api_key, $method, $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

// API anahtarının şifresini çöz
function bb_ai_content_generator_decrypt_api_key($encrypted_key) {
    if (empty($encrypted_key)) {
        return '';
    }
    $key = bb_ai_content_generator_get_encryption_key();
    $method = 'AES-256-CBC';
    $data = base64_decode($encrypted_key);
    $iv_length = openssl_cipher_iv_length($method);
    $iv = substr($data, 0, $iv_length);
    $encrypted_data = substr($data, $iv_length);
    $decrypted = openssl_decrypt($encrypted_data, $method, $key, 0, $iv);
    return $decrypted;
}

// API anahtarını maskele
function bb_ai_content_generator_mask_api_key($api_key) {
    if (empty($api_key)) {
        return '';
    }
    $length = strlen($api_key);
    $visible_chars = 4;
    if ($length <= $visible_chars * 2) {
        return str_repeat('*', $length);
    }
    $masked_section = str_repeat('*', $length - $visible_chars * 2);
    return substr($api_key, 0, $visible_chars) . $masked_section . substr($api_key, -$visible_chars);
}

// İçerik Oluşturucu sayfası
function bb_ai_content_generator_page() {
    // CSS ve JavaScript dosyalarını yükle
    wp_enqueue_style('bb-ai-content-generator-css', plugins_url('css/style.css', __FILE__));
    wp_enqueue_script('bb-ai-content-generator-js', plugins_url('js/script.js', __FILE__), array('jquery'), null, true);

    // API anahtarının girilip girilmediğini kontrol et
    $api_key = bb_ai_content_generator_get_decrypted_api_key();
    $api_key_set = !empty($api_key);

    // AJAX URL, nonce ve API anahtarı durumunu JavaScript'e geç
    wp_localize_script('bb-ai-content-generator-js', 'bb_ai_content_generator_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('bb-ai-content-generator'),
        'api_key_set' => $api_key_set
    ));
    ?>
    <div class="wrap">
        <h1>BB AI Content Generator</h1>
        <form id="content-generator-form">
            <table class="form-table">
                <tr>
                    <th><label for="topic">Topic</label></th>
                    <td><input type="text" id="topic" name="topic" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="title-count">Number of Titles</label></th>
                    <td><input type="number" id="title-count" name="title-count" min="1" max="5" value="3" required></td>
                </tr>
            </table>
            <?php submit_button('Generate Content', 'primary', 'generate-content'); ?>
        </form>
        <div id="progress-container" style="display: none;">
            <div id="progress-bar">
                <div id="progress"></div>
            </div>
            <div id="progress-status"></div>
        </div>
        <div id="results"></div>
        <button id="create-draft" class="button button-secondary" style="display: none;">Generate Draft</button>
    </div>
    <?php
}

// API Ayarları sayfası
function bb_ai_content_generator_api_settings_page() {
    if (isset($_POST['bb_ai_content_generator_api_key'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }
        check_admin_referer('bb_ai_content_generator_api_key_nonce');

        $api_key_input = sanitize_text_field($_POST['bb_ai_content_generator_api_key']);

        // Eğer yeni bir anahtar girildiyse şifrele ve kaydet
        if (!empty($api_key_input) && strpos($api_key_input, '****') === false) {
            $encrypted_key = bb_ai_content_generator_encrypt_api_key($api_key_input);
            update_option('bb_ai_content_generator_api_key', $encrypted_key);
            echo '<div class="updated"><p>API Key saved.</p></div>';
        } else {
            echo '<div class="error"><p>Please enter a valid API key.</p></div>';
        }
    }

    $encrypted_key = get_option('bb_ai_content_generator_api_key', '');
    $api_key = $encrypted_key ? bb_ai_content_generator_decrypt_api_key($encrypted_key) : '';
    $masked_key = $api_key ? bb_ai_content_generator_mask_api_key($api_key) : '';

    ?>
    <div class="wrap">
        <h1>BB AI Content Generator - API Settings</h1>
        <form method="post">
            <?php wp_nonce_field('bb_ai_content_generator_api_key_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="bb_ai_content_generator_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="bb_ai_content_generator_api_key" name="bb_ai_content_generator_api_key" value="<?php echo esc_attr($masked_key); ?>" class="regular-text" placeholder="sk-..." required>
                        <?php if ($masked_key): ?>
                            <p class="description">Your API key has been securely stored. Enter a new key to replace it.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save'); ?>
        </form>
    </div>
    <?php
}

// API anahtarını kullanmadan önce şifresini çöz
function bb_ai_content_generator_get_decrypted_api_key() {
    $encrypted_key = get_option('bb_ai_content_generator_api_key', '');
    return $encrypted_key ? bb_ai_content_generator_decrypt_api_key($encrypted_key) : '';
}

// Eklenti menülerini oluştur
add_action('admin_menu', 'bb_ai_content_generator_menu');

function bb_ai_content_generator_menu() {
    // Ana menü sayfasını ekle
    add_menu_page('BB AI Content Generator', 'Content Generator', 'manage_options', 'bb-ai-content-generator', 'bb_ai_content_generator_page');
    // Alt menü sayfalarını ekle
    add_submenu_page('bb-ai-content-generator', 'API Settings', 'API Settings', 'manage_options', 'bb-ai-api-settings', 'bb_ai_content_generator_api_settings_page');
    add_submenu_page('bb-ai-content-generator', 'API Statistics', 'API Statistics', 'manage_options', 'bb-ai-api-stats', 'bb_ai_content_generator_api_stats_page');
}

// API kullanım istatistiklerini kaydetmek için yeni bir opsiyon ekle
if (!get_option('bb_ai_content_generator_api_usage_stats')) {
    add_option('bb_ai_content_generator_api_usage_stats', array(
        'total_requests' => 0,
        'total_tokens' => 0,
        'last_reset' => current_time('timestamp')
    ));
}

// API İstatistikleri sayfası
function bb_ai_content_generator_api_stats_page() {
    // API kullanım istatistiklerini al
    $stats = get_option('bb_ai_content_generator_api_usage_stats');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('BB AI Content Generator - API Usage Statistics', 'bb-ai-content-generator'); ?></h1>
        <p><?php echo esc_html__('Total Request Count:', 'bb-ai-content-generator'); ?> <?php echo esc_html( $stats['total_requests'] ); ?></p>
        <p><?php echo esc_html__('Total Token Count:', 'bb-ai-content-generator'); ?> <?php echo esc_html( $stats['total_tokens'] ); ?></p>
        <p><?php echo esc_html__('Last Reset:', 'bb-ai-content-generator'); ?> <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $stats['last_reset'] ) ); ?></p>

        <form method="post">
            <?php wp_nonce_field('bb_ai_content_generator_reset_api_stats', 'bb_ai_content_generator_reset_api_stats_nonce'); ?>
            <input type="submit" name="reset_api_stats" class="button button-secondary" value="<?php esc_attr_e('Reset Statistics', 'bb-ai-content-generator'); ?>">
        </form>
    </div>
    <?php
}

// İstatistikleri sıfırlama işlemi
add_action('admin_init', 'bb_ai_content_generator_handle_reset_api_stats');

function bb_ai_content_generator_handle_reset_api_stats() {
    // İstatistikleri sıfırlama isteği kontrolü
    if (isset($_POST['reset_api_stats']) && check_admin_referer('bb_ai_content_generator_reset_api_stats', 'bb_ai_content_generator_reset_api_stats_nonce')) {
        update_option('bb_ai_content_generator_api_usage_stats', array(
            'total_requests' => 0,
            'total_tokens' => 0,
            'last_reset' => current_time('timestamp')
        ));
        // Sayfayı yeniden yönlendir
        wp_redirect(add_query_arg('reset', 'true', wp_get_referer()));
        exit;
    }
}

// API istatistiklerini güncelleme fonksiyonu
function bb_ai_content_generator_update_api_stats($tokens_used) {
    // Mevcut istatistikleri al ve güncelle
    $stats = get_option('bb_ai_content_generator_api_usage_stats');
    $stats['total_requests']++;
    $stats['total_tokens'] += $tokens_used;
    update_option('bb_ai_content_generator_api_usage_stats', $stats);
}


// AJAX işleyicileri
add_action('wp_ajax_generate_titles', 'bb_ai_content_generator_generate_titles_ajax_handler');
add_action('wp_ajax_generate_sections', 'bb_ai_content_generator_generate_sections_ajax_handler');
add_action('wp_ajax_generate_paragraphs', 'bb_ai_content_generator_generate_paragraphs_ajax_handler');
add_action('wp_ajax_create_draft_post', 'bb_ai_content_generator_create_draft_post_ajax_handler');

// Başlık oluşturma AJAX işleyicisi
function bb_ai_content_generator_generate_titles_ajax_handler() {
    check_ajax_referer('bb-ai-content-generator', 'nonce');

    try {
        // Konu ve başlık sayısını al
        $topic = sanitize_text_field($_POST['topic']);
        $count = intval($_POST['count']);


        // OpenAI API'ye istek göndererek başlıkları oluştur
        $titles = bb_ai_content_generator_generate_content("Main topic: $topic\n\nGenerate $count engaging headlines related to this topic:");

        if ($titles) {
            wp_send_json_success($titles);
        } else {
            throw new Exception('Failed to create titles.');
        }
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// Bölüm oluşturma AJAX işleyicisi
function bb_ai_content_generator_generate_sections_ajax_handler() {
    check_ajax_referer('bb-ai-content-generator', 'nonce');

    try {
        // Başlığı al
        $title = sanitize_text_field($_POST['title']);


        // OpenAI API'ye istek göndererek bölümler oluştur
        $sections = bb_ai_content_generator_generate_content("Headline: $title\n\nGenerate 4 subheadings for this headline:");

        if ($sections) {
            wp_send_json_success($sections);
        } else {
            throw new Exception('Failed to create sections.');
        }
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// Paragraf oluşturma AJAX işleyicisi
function bb_ai_content_generator_generate_paragraphs_ajax_handler() {
    check_ajax_referer('bb-ai-content-generator', 'nonce');

    try {
        // Başlık ve bölümü al
        $title = sanitize_text_field($_POST['title']);
        $section = sanitize_text_field($_POST['section']);


        // OpenAI API'ye istek göndererek paragraf oluştur
        $paragraph = bb_ai_content_generator_generate_paragraph($title, $section);

        if ($paragraph) {
            wp_send_json_success($paragraph);
        } else {
            throw new Exception('Failed to generate paragraph.');
        }
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// Paragraf oluşturma fonksiyonu
function bb_ai_content_generator_generate_paragraph($title, $section) {
    $prompt = "Headline: $title\nSection: $section\n\nWrite a paragraph of 150-200 words for this section:";
    $result = bb_ai_content_generator_generate_content($prompt);
    if ($result && is_array($result)) {
        return implode("\n", $result);
    }
    return false;
}

// Taslak yazı oluşturma AJAX işleyicisi
function bb_ai_content_generator_create_draft_post_ajax_handler() {
    check_ajax_referer('bb-ai-content-generator', 'nonce');

    // İçerik ve başlığı al
    $content = wp_kses_post($_POST['content']);
    $title = sanitize_text_field($_POST['title']);

    // Taslak yazıyı oluştur
    $post_id = wp_insert_post(array(
        'post_title'    => $title,
        'post_content'  => $content,
        'post_status'   => 'draft',
        'post_type'     => 'post'
    ));

    if ($post_id) {
        wp_send_json_success(array(
            'message' => 'Draft successfully generated.',
            'edit_url' => get_edit_post_link($post_id, 'raw')
        ));
    } else {
        wp_send_json_error('An error occurred while generating the draft.');
    }
}

// İçerik oluşturma fonksiyonu
function bb_ai_content_generator_generate_content($prompt) {
    $api_key = bb_ai_content_generator_get_decrypted_api_key();

    if (empty($api_key)) {
        throw new Exception('API key not entered. Please enter your API key from the API Settings page.');
    }

    // OpenAI API'ye istek gönder
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'model' => 'gpt-4',
            'messages' => array(
                array('role' => 'system', 'content' => 'You are an expert in content creation.'),
                array('role' => 'user', 'content' => $prompt)
            ),
            'max_tokens' => 1500,
            'n' => 1,
            'temperature' => 0.7,
        ) ),
        'timeout' => 60, // Zaman aşımı süresini 60 saniyeye çıkardık
    ));

    // Hata kontrolü
    if (is_wp_error($response)) {
        return false;
    }

    // Yanıtı işleme
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['choices']) && is_array($body['choices']) && !empty($body['choices'][0]['message']['content'])) {
        $content = trim($body['choices'][0]['message']['content']);
        $items = explode("\n", $content);
        $items = array_filter(array_map('trim', $items));

        // API kullanım istatistiklerini güncelle
        if (isset($body['usage']['total_tokens'])) {
            bb_ai_content_generator_update_api_stats($body['usage']['total_tokens']);
        }

        return $items;
    }

    return false;
}

// Eklenti etkinleştirildiğinde çalışacak fonksiyon
register_activation_hook(__FILE__, 'bb_ai_content_generator_activate');

function bb_ai_content_generator_activate() {
    // Şifreleme anahtarını oluştur
    bb_ai_content_generator_get_encryption_key();
}

// Eklenti devre dışı bırakıldığında çalışacak fonksiyon
register_deactivation_hook(__FILE__, 'bb_ai_content_generator_deactivate');

function bb_ai_content_generator_deactivate() {
    
    delete_transient('bb_ai_content_generator_temp_data');
    
    
    update_option('bb_ai_content_generator_api_usage_stats', array(
        'total_requests' => 0,
        'total_tokens' => 0,
        'last_reset' => current_time('timestamp')
    ));
}


register_uninstall_hook(__FILE__, 'bb_ai_content_generator_uninstall');

function bb_ai_content_generator_uninstall() {
    // Eklenti tamamen kaldırıldığında yapılacak temizlik işlemleri
    
    // Tüm eklenti ayarlarını kaldır
    delete_option('bb_ai_content_generator_api_key');
    delete_option('bb_ai_content_generator_encryption_key');
    delete_option('bb_ai_content_generator_api_usage_stats');
    
    // İsteğe bağlı: Eklentiye ait tüm geçici verileri temizle
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bb_ai_content_generator_%'");
}

?>
