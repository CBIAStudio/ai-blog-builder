<?php
/*
Plugin Name: Creador Blog IA
Description: Genera blogs con IA (texto + imágenes) en una sola llamada con marcadores [IMAGEN: ...]. Sin <h1>, FAQ JSON-LD en <head>, categorías/etiquetas automáticas, stop inmediato, prompts configurables. Compatible con gpt-4.1* (Responses) y gpt-image-1 / gpt-image-1-mini. Incluye reanudación con checkpoint y rellenado de imágenes pendientes.
Version: 5.7.9
Author: webgoh (mejorado por IA)
*/

if (!defined('ABSPATH')) exit;
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

/* =========================================================
   =============== BANDERA STOP / ESTADO ===================
   ========================================================= */
function cbia_set_stop_flag($value = true) { update_option('cbia_stop_generation', $value ? 1 : 0); }
function cbia_check_stop_flag() { return get_option('cbia_stop_generation', 0) == 1; }

/* =========================================================
   ===================== SETTINGS BASE =====================
   ========================================================= */
function cbia_initialize_settings() { register_setting('cbia_settings_group', 'cbia_settings'); }
add_action('admin_init', 'cbia_initialize_settings');

function cbia_get_settings() { return get_option('cbia_settings', array()); }
function cbia_get_openai_api_key() { $s = cbia_get_settings(); return $s['openai_api_key'] ?? ''; }

/* =========================================================
   ===================== HTTP HEADERS ======================
   ========================================================= */
function cbia_http_headers($openai_key) {
    return array('Content-Type' => 'application/json','Authorization' => 'Bearer ' . $openai_key);
}

/* =========================================================
   ========================= LOG ===========================
   ========================================================= */
function cbia_log_message($message) {
    $log = get_option('cbia_activity_log', '');
    $timestamp = current_time('mysql');
    $log .= "[{$timestamp}] $message\n";
    if (strlen($log) > 250000) $log = substr($log, -250000);
    update_option('cbia_activity_log', $log);
}
function cbia_clear_log() { delete_option('cbia_activity_log'); }

/* =========================================================
   =============== MODELOS Y FALLBACKS (4.x) ===============
   ========================================================= */
function cbia_get_supported_models() {
    return array(
        'gpt-4.1-mini'  => 'GPT-4.1 mini (Responses)',
        'gpt-4.1'       => 'GPT-4.1 (Responses)',
        'gpt-4.1-nano'  => 'GPT-4.1 nano (Responses)',
        'gpt-4o-mini'   => 'GPT-4o mini (Responses)',
        'gpt-4o'        => 'GPT-4o (Chat)',
        'gpt-4-turbo'   => 'GPT-4 Turbo (Chat)',
        'gpt-4'         => 'GPT-4 (Chat)',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Chat)'
    );
}
function cbia_model_fallback_chain($preferred) {
    $chain = array('gpt-4.1-mini','gpt-4.1','gpt-4.1-nano','gpt-4o-mini','gpt-4o','gpt-4-turbo','gpt-4','gpt-3.5-turbo');
    if (!in_array($preferred, $chain, true)) array_unshift($chain, $preferred);
    else $chain = array_unique(array_merge(array($preferred), $chain));
    return $chain;
}
function cbia_is_responses_model($m) { $m = strtolower($m); return (strpos($m,'gpt-4.1') === 0) || ($m === 'gpt-4o-mini'); }

/* =========================================================
   =================== UTIL SEO/HTML =======================
   ========================================================= */
function cbia_strip_h1_to_h2($html) { $html = preg_replace('/<h1\b([^>]*)>/i','<h2$1>',$html); $html = preg_replace('/<\/h1>/i','</h2>',$html); return $html; }
function cbia_strip_document_wrappers($html) {
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) $html = $m[1];
    $html = preg_replace('/<!DOCTYPE.*?>/is', '', $html);
    $html = preg_replace('/<\/?(html|head|body|meta|title|script|style)[^>]*>/is', '', $html);
    return trim($html);
}
function cbia_first_paragraph_text($html) { if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) return wp_strip_all_tags($m[1]); return wp_strip_all_tags($html); }
function cbia_sanitize_alt_from_desc($desc) { $alt = wp_strip_all_tags($desc); $alt = preg_replace('/\s+/', ' ', $alt); return trim(mb_substr($alt,0,120)); }
function cbia_build_img_alt($title,$section_name,$summary_prompt) { $base=cbia_sanitize_alt_from_desc($summary_prompt); $parts=array_filter(array($title,ucfirst($section_name),$base)); $alt=implode(' – ',array_unique($parts)); return trim(mb_substr($alt,0,140)); }
function cbia_slugify($text) { $text=remove_accents($text); $text=strtolower($text); $text=preg_replace('/[^a-z0-9]+/','-',$text); $text=preg_replace('/-+/','-',$text); return trim(mb_substr($text,0,190),'-'); }
function cbia_normalize_for_match($str){ $str=remove_accents($str); $str=strtolower($str); return $str; }

/* =========================================================
   =========== EXTRACTOR RESPONSES OUTPUT TEXT =============
   ========================================================= */
function cbia__extract_text_from_responses_payload($data) {
    if (!empty($data['output_text']) && is_string($data['output_text'])) return trim($data['output_text']);
    if (!empty($data['output'][0]['content']) && is_array($data['output'][0]['content'])) {
        $parts=array();
        foreach($data['output'][0]['content'] as $seg){
            if (!empty($seg['text'])) { if (is_string($seg['text'])) $parts[]=$seg['text']; elseif(!empty($seg['text']['value'])) $parts[]=$seg['text']['value']; }
            elseif (is_string($seg)) $parts[]=$seg;
        }
        $txt=trim(implode("\n",$parts)); if ($txt!=='') return $txt;
    }
    if (!empty($data['choices'][0]['message']['content'])) {
        $c=$data['choices'][0]['message']['content'];
        if (is_string($c)) return trim($c);
        if (is_array($c)) {
            $parts=array(); foreach($c as $seg){ if (is_string($seg)) $parts[]=$seg; elseif(!empty($seg['text'])) { if(is_string($seg['text'])) $parts[]=$seg['text']; elseif(!empty($seg['text']['value'])) $parts[]=$seg['text']['value']; } }
            $txt=trim(implode("\n",$parts)); if($txt!=='') return $txt;
        }
    }
    if (!empty($data['content']) && is_array($data['content'])) {
        $parts=array(); foreach($data['content'] as $seg){ if(!empty($seg['text'])){ if(is_string($seg['text'])) $parts[]=$seg['text']; elseif(!empty($seg['text']['value'])) $parts[]=$seg['text']['value']; } }
        $txt=trim(implode("\n",$parts)); if ($txt!=='') return $txt;
    }
    return '';
}

/* =========================================================
   ============== OPENAI – TEXTO (Responses/Chat) ==========
   ========================================================= */
function cbia_generate_content_openai($title,$final_prompt,$max_retries=2){
    if (cbia_check_stop_flag()) { cbia_log_message("Proceso detenido antes de solicitar contenido."); return false; }
    $openai_key = cbia_get_openai_api_key(); if(!$openai_key){ cbia_log_message("No hay clave OpenAI."); return false; }

    $settings = cbia_get_settings();
    $preferred = $settings['openai_model'] ?? 'gpt-4.1-mini';
    $fallbacks = cbia_model_fallback_chain($preferred);
    $fallbacks = array_values(array_filter($fallbacks,function($m){ return strpos($m,'gpt-5')!==0; }));
    if (empty($fallbacks)) $fallbacks=array('gpt-4.1-mini','gpt-4.1','gpt-4o-mini','gpt-4o');
    $temperature = floatval($settings['openai_temperature'] ?? 0.7);

    $sys = "Eres un redactor SEO experto. Devuelve HTML simple (<h2>-<h3>, <p>, <ul>, <li>). NO uses <h1> ni envolturas <html>/<head>/<body>.";
    $messages = array(array('role'=>'system','content'=>$sys), array('role'=>'user','content'=>$final_prompt));

    foreach($fallbacks as $model){
        if (cbia_is_responses_model($model)) {
            $attempt=1;
            do{
                if (cbia_check_stop_flag()) { cbia_log_message("Detenido (responses: {$model})."); return false; }
                cbia_log_message("Intento $attempt: Responses {$model} para '{$title}'.");
                $payload=array('model'=>$model,'input'=>$messages,'max_output_tokens'=>2200);
                $resp=wp_remote_post('https://api.openai.com/v1/responses',array(
                    'headers'=>cbia_http_headers($openai_key),'body'=>json_encode($payload),'timeout'=>150));
                if(!is_wp_error($resp)){
                    $code=wp_remote_retrieve_response_code($resp);
                    $data=json_decode(wp_remote_retrieve_body($resp),true);
                    if(!empty($data['error']['message'])){ cbia_log_message("OpenAI (responses) error [HTTP $code]: ".$data['error']['message']); }
                    else { $content=cbia__extract_text_from_responses_payload($data); if($content!=='') return trim($content); }
                } else cbia_log_message("HTTP error (responses): ".$resp->get_error_message());
                $attempt++; if($attempt<=$max_retries) sleep(2);
            } while($attempt<=$max_retries);

            // Respaldo: input plano
            $attempt=1;
            do{
                if (cbia_check_stop_flag()) { cbia_log_message("Detenido (responses plano: {$model})."); return false; }
                cbia_log_message("Intento $attempt: Responses {$model} (input plano) para '{$title}'.");
                $payload=array('model'=>$model,'input'=>$final_prompt,'max_output_tokens'=>2200);
                $resp=wp_remote_post('https://api.openai.com/v1/responses',array(
                    'headers'=>cbia_http_headers($openai_key),'body'=>json_encode($payload),'timeout'=>150));
                if(!is_wp_error($resp)){
                    $code=wp_remote_retrieve_response_code($resp);
                    $data=json_decode(wp_remote_retrieve_body($resp),true);
                    if(!empty($data['error']['message'])){ cbia_log_message("OpenAI (responses plano) error [HTTP $code]: ".$data['error']['message']); }
                    else { $content=cbia__extract_text_from_responses_payload($data); if($content!=='') return $content; }
                } else cbia_log_message("HTTP error (responses plano): ".$resp->get_error_message());
                $attempt++; if($attempt<=$max_retries) sleep(2);
            } while($attempt<=$max_retries);
            continue;
        }

        // Chat
        $attempt=1;
        do{
            if (cbia_check_stop_flag()) { cbia_log_message("Detenido (chat {$model})."); return false; }
            cbia_log_message("Intento $attempt: Chat {$model} para '{$title}'.");
            $resp=wp_remote_post('https://api.openai.com/v1/chat/completions',array(
                'headers'=>cbia_http_headers($openai_key),
                'body'=>json_encode(array('model'=>$model,'messages'=>$messages,'temperature'=>$temperature,'max_tokens'=>2000)),
                'timeout'=>150
            ));
            if(!is_wp_error($resp)){
                $code=wp_remote_retrieve_response_code($resp);
                $data=json_decode(wp_remote_retrieve_body($resp),true);
                if(!empty($data['choices'][0]['message']['content'])) return trim($data['choices'][0]['message']['content']);
                if(!empty($data['error']['message'])) cbia_log_message("OpenAI (chat) error [HTTP $code]: ".$data['error']['message']);
            } else cbia_log_message("HTTP error (chat): ".$resp->get_error_message());
            $attempt++; if($attempt<=$max_retries) sleep(2);
        } while($attempt<=$max_retries);
    }

    cbia_log_message("No se pudo obtener contenido tras probar modelos/endpoints.");
    return false;
}

/* =========================================================
   ============== RESUMEN PARA PROMPT DE IMAGEN =============
   ========================================================= */
function cbia_summarize_text($section_text,$section_name,$title){
    $prompt="Resume en UNA sola frase descriptiva, concreta y sin comillas este contenido sobre '{$title}'. Será el prompt de imagen para '{$section_name}'. Evita instrucciones y texto superpuesto.\n\n".wp_strip_all_tags($section_text);
    $summary=cbia_generate_content_openai($title,$prompt,1);
    return $summary ? strip_tags($summary) : $title;
}

/* =========================================================
   ============= OPENAI – IMÁGENES + FALLBACK ===============
   ========================================================= */
function cbia_generate_section_image($summary_prompt,$section,$title,$is_banner=false,$tipo_log=''){
    if (cbia_check_stop_flag()) { cbia_log_message("Detenido antes de generar imagen ($section)."); return false; }
    $settings=cbia_get_settings();
    $openai_key=cbia_get_openai_api_key();
    if(!$openai_key){ cbia_log_message("Sin clave para imágenes."); return false; }

    $format_size_map=array('square'=>'1024x1024','vertical'=>'1024x1536','panoramic'=>'1536x1024','banner'=>'1536x1024');
    $format=$settings["image_format_".$section] ?? $settings['image_format'] ?? 'panoramic';
    $size=$format_size_map[$format] ?? '1536x1024';

    $prompt_base = $settings["prompt_img_".$section] ?? '';
    if ($prompt_base==='') {
        if     ($section==='intro')      $prompt_base='Imagen editorial realista, sin texto, que represente la idea central de "{title}". Iluminación natural, composición limpia.';
        elseif ($section==='body')       $prompt_base='Imagen realista que ilustre el concepto clave del bloque de "{title}". Sin texto ni logos. Enfoque nítido.';
        elseif ($section==='conclusion') $prompt_base='Imagen realista que refuerce el resultado/beneficio final de "{title}". Estética coherente con las anteriores.';
        elseif ($section==='faq')        $prompt_base='Imagen realista de soporte para Preguntas frecuentes de "{title}". Minimalista y clara, sin texto.';
        else                             $prompt_base='Imagen editorial realista, sin texto visible, sobre:';
    }
    $prompt_base=str_replace('{title}',$title,$prompt_base);

    if ($format==='banner' || $is_banner) {
        $prompt_base = "Toma amplia (long shot), sujeto pequeño, headroom 25–35%, márgenes laterales generosos, sin primeros planos ni recortes de cabeza. "
                     . "Encuadre amplio, motivo centrado, apto para recorte panorámico. Evita elementos críticos pegados a bordes. ".$prompt_base;
    }

    $prompt_img=trim($prompt_base." ".$summary_prompt);
    $alt_text=cbia_build_img_alt($title,$section,$summary_prompt);

    $make_request=function($model,$prompt_img) use($openai_key,$size,$section,$title,$alt_text,$tipo_log){
        $max_try=3; $sleep=2;
        for($t=1;$t<=$max_try;$t++){
            $tipo_txt=$tipo_log!==''?$tipo_log:$section;
            cbia_log_message("Solicitando imagen IA para '{$tipo_txt}' con {$model} (tamaño: $size)... intento $t/$max_try");
            $response=wp_remote_post('https://api.openai.com/v1/images/generations',array(
                'headers'=>cbia_http_headers($openai_key),
                'body'=>json_encode(array('model'=>$model,'prompt'=>$prompt_img,'n'=>1,'size'=>$size)),
                'timeout'=>150
            ));
            if(is_wp_error($response)){ cbia_log_message("HTTP error imagen ({$tipo_txt}/$model): ".$response->get_error_message()); return array(false,'wp_error'); }

            $code=wp_remote_retrieve_response_code($response);
            $body=wp_remote_retrieve_body($response);

            if($code==429 && $t<$max_try){ cbia_log_message("429 rate limit ($model). Reintentando en {$sleep}s..."); sleep($sleep); $sleep*=2; continue; }

            $result=json_decode($body,true);
            if(isset($result['error']['message'])){ cbia_log_message("OpenAI imagen ($model) error: ".$result['error']['message']." [HTTP $code]"); if($code>=400 && $code<500) return array(false,'model_error'); continue; }

            $image_bytes=null;
            if(!empty($result['data'][0]['b64_json'])) $image_bytes=base64_decode($result['data'][0]['b64_json']);
            elseif(!empty($result['data'][0]['url'])){
                $img_resp=wp_remote_get($result['data'][0]['url'],array('timeout'=>60));
                if(!is_wp_error($img_resp) && wp_remote_retrieve_response_code($img_resp)===200){ $image_bytes=wp_remote_retrieve_body($img_resp); }
                else cbia_log_message("Fallo descargando URL de imagen ({$tipo_txt}/$model).");
            }

            if(!$image_bytes){ cbia_log_message("Respuesta sin datos de imagen ($model) [HTTP $code]."); continue; }

            $filename=sanitize_title($title.'-'.$section).'-'.uniqid().'.png';
            $upload=wp_upload_bits($filename,null,$image_bytes);
            if($upload && empty($upload['error'])){
                $wp_filetype=wp_check_filetype($filename,null);
                $attachment=array('post_mime_type'=>$wp_filetype['type'],'post_title'=>$filename,'post_content'=>'','post_status'=>'inherit');
                $attach_id=wp_insert_attachment($attachment,$upload['file']);
                require_once(ABSPATH.'wp-admin/includes/image.php');
                $attach_data=wp_generate_attachment_metadata($attach_id,$upload['file']);
                wp_update_attachment_metadata($attach_id,$attach_data);
                update_post_meta($attach_id,'_wp_attachment_image_alt',$alt_text);
                return array(array('id'=>$attach_id,'url'=>wp_get_attachment_url($attach_id),'alt'=>$alt_text),null);
            } else {
                cbia_log_message("Error subiendo imagen ({$tipo_txt}/$model): ".($upload['error'] ?? 'desconocido'));
                return array(false,'upload_error');
            }
        }
        return array(false,null);
    };

    $models_img=array('gpt-image-1-mini','gpt-image-1');
    foreach($models_img as $mimg){
        list($img,$err)=$make_request($mimg,$prompt_img);
        if($img) return $img;
        if($err==='model_error') continue;
    }
    cbia_log_message("Respuesta sin imagen IA ($section) tras probar modelos: ".implode(', ',$models_img).".");
    return false;
}

/* =========================================================
   ================= TAGS (máx 5) ==========================
   ========================================================= */
function cbia_get_allowed_tags(){
    $settings=cbia_get_settings();
    $tags_string=$settings['default_tags'] ?? '';
    $tags_array=array_filter(array_map('trim',explode(',',$tags_string)));
    return array_slice($tags_array,0,5);
}

/* =========================================================
   ================== CATEGORÍAS ROBUSTAS ===================
   ========================================================= */
function cbia_ensure_category_exists($cat_name){
    $cat_name=trim($cat_name); if($cat_name==='') return 0;
    $existing=term_exists($cat_name,'category');
    if($existing) return is_array($existing)?intval($existing['term_id']):intval($existing);
    $slug=cbia_slugify($cat_name); if($slug==='') $slug='cat-'.wp_generate_password(6,false);
    $created=wp_insert_term(mb_substr($cat_name,0,180),'category',array('slug'=>$slug));
    if(is_wp_error($created)){
        cbia_log_message("Error creando categoría '{$cat_name}': ".$created->get_error_message());
        $slug2=$slug.'-'.substr(md5(uniqid('',true)),0,6);
        $created=wp_insert_term(mb_substr($cat_name,0,160),'category',array('slug'=>$slug2));
        if(is_wp_error($created)){ cbia_log_message("Segundo intento fallido para '{$cat_name}': ".$created->get_error_message()); return 0; }
    }
    return intval($created['term_id']);
}
function cbia_determine_categories_by_mapping($title,$content_html){
    $settings=cbia_get_settings();
    $mapping=$settings['keywords_to_categories'] ?? "";
    $lines=array_filter(array_map('trim',explode("\n",$mapping)));

    $norm_title=cbia_normalize_for_match($title);
    $norm_content=cbia_normalize_for_match(wp_strip_all_tags(mb_substr($content_html,0,3000)));

    $found=array();
    foreach($lines as $line){
        $parts=explode(':',$line,2); if(count($parts)!==2) continue;
        $cat=trim($parts[0]); $keywords=array_filter(array_map('trim',explode(',',$parts[1])));
        $matched=false;
        foreach($keywords as $kw){
            $kw_norm=preg_quote(cbia_normalize_for_match($kw),'/');
            $pattern='/(?<![a-z0-9])'.$kw_norm.'(?![a-z0-9])/i';
            if(preg_match($pattern,$norm_title) || preg_match($pattern,$norm_content)){ $matched=true; break; }
        }
        if($matched) $found[]=$cat;
    }
    $found=array_values(array_unique($found));
    return array_slice($found,0,3);
}
function cbia_get_category_ids($categories){
    $ids=array(); foreach($categories as $cat_name){ $id=cbia_ensure_category_exists($cat_name); if($id) $ids[]=$id; }
    return $ids;
}

/* =========================================================
   ================== SEO (Yoast básico) ====================
   ========================================================= */
function cbia_generate_focus_keyphrase($title,$content){
    $words=preg_split('/\s+/',wp_strip_all_tags($title)); return trim(implode(' ',array_slice($words,0,4)));
}
function cbia_generate_meta_description($title,$content){
    $content=cbia_strip_document_wrappers($content); $base=cbia_first_paragraph_text($content); $t=trim(wp_strip_all_tags($title));
    if($t!==''){ $pattern='/^'.preg_quote($t,'/').'\s*[:\-–—]?\s*/iu'; $base=preg_replace($pattern,'',$base); }
    $desc=trim(mb_substr($base,0,155)); if($desc!=='' && !preg_match('/[.!?]$/u',$desc)) $desc.='...'; return $desc;
}

/* =========================================================
   ================= FAQ H2 (detección) =====================
   ========================================================= */
function cbia_faq_h2_patterns(){ return array(
    '/<h2[^>]*>\s*(preguntas\s+frecuentes)\s*<\/h2>/i',
    '/<h2[^>]*>\s*(domande\s+frequenti)\s*<\/h2>/i',
    '/<h2[^>]*>\s*(frequently\s+asked\s+questions)\s*<\/h2>/i',
    '/<h2[^>]*>\s*faq\s*<\/h2>/i'
); }
function cbia_find_faq_h2_position($html){
    foreach(cbia_faq_h2_patterns() as $pat){
        if(preg_match($pat,$html,$mm,PREG_OFFSET_CAPTURE)) return intval($mm[0][1]);
    }
    return -1;
}

/* =========================================================
   =========== MARCADORES (normal y PENDIENTE) ==============
   ========================================================= */
function cbia_marker_regex(){ return '/\[(IMAGEN|IMAGE|IMMAGINE|IMAGEM|BILD|FOTO)\s*:\s*([^\]]+?)\]/i'; }
function cbia_marker_pending_regex(){ return '/\[IMAGEN_PENDIENTE\s*:\s*([^\]]+?)\]/i'; }

function cbia_extract_image_markers_full($html){
    $markers=array();
    if(preg_match_all(cbia_marker_regex(),$html,$m,PREG_OFFSET_CAPTURE)){
        foreach($m[0] as $idx=>$fullCap){
            $full=$fullCap[0]; $pos=$fullCap[1]; $desc=$m[2][$idx][0];
            $markers[]=array('desc'=>$desc,'full'=>$full,'full_pos'=>$pos);
        }
    }
    return $markers;
}
function cbia_extract_pending_markers_full($html){
    $markers=array();
    if(preg_match_all(cbia_marker_pending_regex(),$html,$m,PREG_OFFSET_CAPTURE)){
        foreach($m[0] as $idx=>$fullCap){
            $full=$fullCap[0]; $pos=$fullCap[1]; $desc=$m[1][$idx][0];
            $markers[]=array('desc'=>$desc,'full'=>$full,'full_pos'=>$pos);
        }
    }
    return $markers;
}
function cbia_normalize_markers_layout($html){
    $html=preg_replace(cbia_marker_regex(),"\n$0\n",$html);
    $html=preg_replace_callback('/<p[^>]*>(.*?)<\/p>/is',function($pm){
        $block=$pm[0];
        if(!preg_match(cbia_marker_regex(),$block)) return $block;
        $block=preg_replace(cbia_marker_regex(),'</p>'."\n".'$0'."\n".'<p>',$block);
        $block=preg_replace('/<p>\s*<\/p>/is','',$block);
        return $block;
    },$html);
    $html=preg_replace("/\n{3,}/","\n\n",$html);
    return $html;
}
function cbia_replace_marker_at(&$html,$marker_full,$replacement,&$searchFrom){
    $pos=strpos($html,$marker_full,$searchFrom);
    if($pos===false){ $html=preg_replace('/'.preg_quote($marker_full,'/').'/',$replacement,$html,1); $searchFrom=0; return; }
    $html=substr($html,0,$pos).$replacement.substr($html,$pos+strlen($marker_full));
    $searchFrom=$pos+strlen($replacement);
}
function cbia_replace_next_marker_with_img_exact(&$html,$img_url_or_id,$marker_full,$alt,&$searchFrom,$class='cbia-banner'){
    $final_url=is_numeric($img_url_or_id)?wp_get_attachment_url(intval($img_url_or_id)):$img_url_or_id;
    if(!$final_url && is_numeric($img_url_or_id)) $final_url=wp_get_attachment_url(intval($img_url_or_id));
    $base_style="display:block;width:100%;height:auto;margin:15px 0;";
    $banner_style="height:450px;object-fit:cover;object-position:50% 30%;";
    $style=$base_style.($class==='cbia-banner'?$banner_style:'');
    $tag="<img src='".esc_url($final_url)."' alt='".esc_attr($alt)."' class='".esc_attr($class)."' style='".esc_attr($style)."'>";
    cbia_replace_marker_at($html,$marker_full,$tag,$searchFrom);
}

/* =========================================================
   ====== Autoinserción de marcadores (si no hay) ==========
   ========================================================= */
function cbia_force_insert_markers($html,$title,$images_limit){
    $inserted=0;
    if(preg_match('/<p[^>]*>.*?<\/p>/is',$html,$m,PREG_OFFSET_CAPTURE)){
        $p_full=$m[0][0]; $p_len=strlen($p_full); $pos0=$m[0][1];
        $desc=cbia_sanitize_alt_from_desc(cbia_first_paragraph_text($p_full));
        $marker="\n[IMAGEN: {$desc}]\n"; $html=substr($html,0,$pos0+$p_len).$marker.substr($html,$pos0+$p_len); $inserted++;
    }
    $faq_pos=cbia_find_faq_h2_position($html);
    if($inserted<$images_limit && $faq_pos>=0){
        $marker="\n[IMAGEN: soporte visual para las preguntas frecuentes]\n";
        $html=substr($html,0,$faq_pos).$marker.substr($html,$faq_pos); $inserted++;
    }
    if($inserted<$images_limit){
        $desc="cierre visual coherente con el tema de \"{$title}\"";
        $marker="\n[IMAGEN: {$desc}]\n"; $html.=$marker; $inserted++;
    }
    return $html;
}

/* =========================================================
   === Detectar tipo de marcador por contexto (sección) =====
   ========================================================= */
function cbia_detect_marker_type($html,$marker_pos,$is_first){
    if($is_first) return 'destacada';
    $len=strlen($html);
    $faq_h2_pos=cbia_find_faq_h2_position($html);
    if($faq_h2_pos>=0 && $marker_pos>$faq_h2_pos) return 'faq';
    if($marker_pos>max(0.85*$len,$len-3000)) return 'conclusion';
    return 'cuerpo';
}

/* =========================================================
   ========= FIX FAQ respuestas con punto final =============
   ========================================================= */
function cbia_fix_faq_end_punctuation($html){
    if(!preg_match('/(<h2[^>]*>.*?(preguntas\s+frecuentes|domande\s+frequenti|frequently\s+asked\s+questions|faq).*?<\/h2>)(.*)$/is',$html,$m)) return $html;
    $head=substr($html,0,strpos($html,$m[1])); $faq_h2=$m[1]; $rest=$m[3];
    $parts=preg_split('/(<h3[^>]*>.*?<\/h3>)/is',$rest,-1,PREG_SPLIT_DELIM_CAPTURE);
    if(!$parts || count($parts)<3) return $html;

    $new_faq=''; $faq_count=0;
    for($i=0;$i<count($parts)-1;$i+=2){
        $h3=$parts[$i]; $ans=$parts[$i+1] ?? '';
        if(!preg_match('/^<h3/i',$h3)){ $new_faq.=$h3; $i--; continue; }
        $faq_count++; if($faq_count>6) break;

        $answer_block=$ans;
        $answer_block=preg_replace_callback('/<p[^>]*>(.*?)<\/p>/is',function($mm){
            $txt=$mm[1]; $txt_stripped=rtrim($txt); if(!preg_match('/[.!?…]$/u',$txt_stripped)) $txt_stripped.='.'; return '<p>'.$txt_stripped.'</p>';
        },$answer_block);

        if(!preg_match('/<p[^>]*>.*?<\/p>/is',$answer_block)){
            $answer_block=rtrim($answer_block);
            if(!preg_match('/[.!?]$/u',wp_strip_all_tags($answer_block))) $answer_block.='.';
        }

        $plain=trim(wp_strip_all_tags($answer_block)); if(mb_strlen($plain)<40){ $faq_count--; continue; }
        $new_faq.=$h3.$answer_block;
    }
    return $head.$faq_h2.$new_faq;
}

/* =========================================================
   ================= POST EXISTS BY TITLE ===================
   ========================================================= */
function cbia_post_exists_by_title($title){
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_status IN ('publish','future','draft','pending')",$title));
}

/* =========================================================
   ======== CREAR POST + SEO + CATEGORÍAS/ETIQUETAS =========
   ========================================================= */
function cbia_create_post_in_wp($title,$full_post_content,$img_intro_attach=null,$post_date=null,$faq_json_ld=''){
    $settings=cbia_get_settings();
    $full_post_content=cbia_strip_document_wrappers($full_post_content);
    $full_post_content=cbia_strip_h1_to_h2($full_post_content);

    $post_status=$post_date?'future':'publish';
    $postarr=array('post_title'=>$title,'post_content'=>$full_post_content,'post_status'=>$post_status);
    if($post_date){ $postarr['post_date']=$post_date; $postarr['post_date_gmt']=get_gmt_from_date($post_date); }

    $post_id=wp_insert_post($postarr);

    if($post_id){
        if($faq_json_ld) update_post_meta($post_id,'_cbia_faq_json_ld',$faq_json_ld);

        $cats_from_map=cbia_determine_categories_by_mapping($title,$full_post_content);
        if(empty($cats_from_map)){ $default_cat=$settings['default_category'] ?? 'Noticias'; $cats_from_map=array($default_cat); }
        $cat_ids=cbia_get_category_ids($cats_from_map);
        if(!empty($cat_ids)) wp_set_post_categories($post_id,$cat_ids,false);

        $allowed_tags=cbia_get_allowed_tags();
        if(!empty($allowed_tags)) wp_set_post_tags($post_id,$allowed_tags,false);

        if($img_intro_attach && isset($img_intro_attach['id'])) set_post_thumbnail($post_id,$img_intro_attach['id']);

        $meta_description=cbia_generate_meta_description($title,$full_post_content);
        $focus_keyphrase =cbia_generate_focus_keyphrase($title,$full_post_content);
        update_post_meta($post_id,'_yoast_wpseo_metadesc',$meta_description);
        update_post_meta($post_id,'_yoast_wpseo_focuskw',$focus_keyphrase);

        // Marca de trazabilidad del plugin
        update_post_meta($post_id,'_cbia_created','1');
    }
    return $post_id;
}

/* =========================================================
   ============= FAQ JSON-LD → HEAD (single) ================
   ========================================================= */
function cbia_output_faq_json_ld_in_head(){
    if(!is_singular('post')) return;
    $post_id=get_the_ID(); if(!$post_id) return;
    $json=get_post_meta($post_id,'_cbia_faq_json_ld',true); if(!$json) return;
    $decoded=json_decode($json,true);
    if(json_last_error()===JSON_ERROR_NONE) $json=wp_json_encode($decoded);
    else $json=preg_replace('/\\\\u([0-9a-fA-F]{4})/','\\u$1',$json);
    echo "\n<script type=\"application/ld+json\">".$json."</script>\n";
}
add_action('wp_head','cbia_output_faq_json_ld_in_head',5);

/* =========================================================
   ============ FAQ JSON-LD BUILDER (desde HTML) ============
   ========================================================= */
function cbia_build_faq_json_ld($faq_html){
    if(!$faq_html) return '';
    $out=array();
    $pattern_q='/<h3[^>]*>(.*?)<\/h3>/i';
    if(preg_match_all($pattern_q,$faq_html,$qs)){
        $questions=$qs[1];
        $parts=preg_split($pattern_q,$faq_html);
        for($i=1;$i<count($parts);$i++){
            $answer_html=trim($parts[$i]);
            if(preg_match('/<p[^>]*>(.*?)<\/p>/is',$answer_html,$am))       $answer=wp_strip_all_tags($am[1]);
            elseif(preg_match('/<li[^>]*>(.*?)<\/li>/is',$answer_html,$am2)) $answer=wp_strip_all_tags($am2[1]);
            else                                                             $answer=wp_strip_all_tags($answer_html);
            $q=wp_strip_all_tags($questions[$i-1]);
            if($q!=='' && $answer!==''){
                $answer=rtrim($answer); if(!preg_match('/[.!?]$/u',$answer)) $answer.='.';
                $out[]=array('question'=>$q,'answer'=>$answer);
            }
        }
    }
    if(empty($out)) return '';
    $data=array("@context"=>"https://schema.org","@type"=>"FAQPage","mainEntity"=>array_map(function($qa){
        return array("@type"=>"Question","name"=>$qa['question'],"acceptedAnswer"=>array("@type"=>"Answer","text"=>$qa['answer']));
    },$out));
    return wp_json_encode($data);
}

/* =========================================================
   =========== VARIANTES DE LONGITUD DE POST =================
   ========================================================= */
function cbia_apply_length_variant_to_prompt($base_prompt,$variant){
    $addon=($variant==='short') ? " Limita la longitud total a ~1000 palabras (breve). Usa 2 bloques principales en <h2>."
        : (($variant==='long') ? " Amplía la longitud a ~2200 palabras (extensa). Usa 4 bloques principales en <h2> (200–300 palabras cada uno)."
        : " Mantén ~1600–1800 palabras (media) con 3 bloques principales en <h2>.");
    return $base_prompt.$addon;
}

/* =========================================================
   =========== NUEVO: FECHA CONSISTENTE Y CHECKPOINT =========
   ========================================================= */
/**
 * _cbia_last_scheduled_at: última fecha programada/publicada (Y-m-d H:i:s, tz del sitio)
 * cbia_checkpoint: {
 *   queue: array de títulos pendientes,
 *   idx: índice actual,
 *   base_ts: timestamp base de la primera programada,
 *   created_total: creadas en toda la ejecución (informativo),
 *   running: bool
 * }
 */
function cbia_get_last_scheduled_at(){
    return get_option('_cbia_last_scheduled_at','');
}
function cbia_set_last_scheduled_at($datetime){
    if($datetime) update_option('_cbia_last_scheduled_at',$datetime);
}

function cbia_checkpoint_clear(){ delete_option('cbia_checkpoint'); }
function cbia_checkpoint_get(){ $cp=get_option('cbia_checkpoint',array()); return is_array($cp)?$cp:array(); }
function cbia_checkpoint_save($cp){ update_option('cbia_checkpoint',$cp); }

/**
 * Calcula la próxima fecha según:
 * - Si hay first_publication_datetime definido y nunca se publicó nada por el plugin, usar esa.
 * - Si no hay first definida:
 *     - si nunca se publicó nada, publicar ahora (primera).
 *     - si ya hay última, sumar intervalo.
 */
function cbia_compute_next_datetime($interval_days){
    $s=cbia_get_settings();
    $first_dt=$s['first_publication_datetime'] ?? '';
    $last=cbia_get_last_scheduled_at();
    $tz=function_exists('wp_timezone')?wp_timezone():new DateTimeZone(wp_timezone_string());

    if($last===''){
        if($first_dt!==''){
            // Primera programada personalizada
            $dt=date_create_from_format('Y-m-d H:i:s',$first_dt,$tz);
            if($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s');
        }
        // Primera inmediata (publicada ahora)
        return ''; // '' = publicar ahora
    }

    // Ya existe una última -> sumamos intervalo
    try{
        $last_dt=new DateTime($last,$tz);
        $last_dt->modify("+{$interval_days} day");
        return $last_dt->format('Y-m-d H:i:s');
    }catch(Exception $e){ cbia_log_message("Error calculando próxima fecha: ".$e->getMessage()); return ''; }
}

/* =========================================================
   =========== GENERACIÓN – MODO ÚNICO (marcadores) =========
   ========================================================= */
function cbia_generate_single_with_placeholders_mode($title,$post_date=null){
    $settings=cbia_get_settings();
    $variant =$settings['post_length_variant'] ?? 'medium';

    $prompt_unico=$settings['prompt_single_all'] ??
        "Escribe un POST COMPLETO en HTML para \"{title}\" con objetivo de ~1600–1800 palabras (±10%)."
        ."\nTono profesional y cercano. Estructura EXACTA, sin añadir otras secciones:"
        ."\n- Párrafo inicial en <p> sin usar la palabra \"Introducción\" (150–180 palabras)"
        ."\n- 3 bloques principales con <h2> y, si aporta, <h3> (200–300 palabras por bloque; usa listas <ul><li>…</li></ul> cuando ayuden a la claridad)"
        ."\n- <h2>Preguntas frecuentes</h2> con 6 FAQs en formato <h3>Pregunta</h3><p>Respuesta</p> (100–130 palabras por respuesta)."
        ."\nInstrucción CRÍTICA: ninguna respuesta debe cortarse y TODAS las respuestas deben terminar en punto final."
        ."\nInserta marcadores de imagen donde aporten valor con el formato EXACTO:"
        ."\n[IMAGEN: descripción breve, concreta, sin texto ni marcas de agua, estilo realista/editorial]"
        ."\nReglas de obligado cumplimiento:"
        ."\n• NO uses <h1>."
        ."\n• NO añadas sección de conclusión ni CTA final."
        ."\n• NO incluyas <!DOCTYPE>, <html>, <head>, <body>, <script> ni <style>."
        ."\n• NO enlaces a webs externas (si es necesario, menciona '(enlace interno)' como texto plano)."
        ."\n• Evita redundancias y muletillas.";
    $prompt_unico=str_replace('{title}',$title,$prompt_unico);
    $prompt_unico=cbia_apply_length_variant_to_prompt($prompt_unico,$variant);

    $html=cbia_generate_content_openai($title,$prompt_unico);
    if(!$html) return false;

    cbia_log_message("Preview HTML (200 chars): ".mb_substr(wp_strip_all_tags($html),0,200));

    $html=cbia_strip_document_wrappers($html);
    $html=cbia_strip_h1_to_h2($html);
    $html=cbia_fix_faq_end_punctuation($html);
    $html=cbia_normalize_markers_layout($html);

    $markers=cbia_extract_image_markers_full($html);
    cbia_log_message("Marcadores [IMAGEN|IMAGE|IMMAGINE|IMAGEM|BILD|FOTO] detectados: ".count($markers));

    $images_limit=intval($settings['images_limit'] ?? 3);
    if($images_limit<1) $images_limit=1; if($images_limit>4) $images_limit=4;

    if(empty($markers)){
        cbia_log_message("No se detectaron marcadores. Autoinsertando hasta {$images_limit}…");
        $html=cbia_force_insert_markers($html,$title,$images_limit);
        $html=cbia_normalize_markers_layout($html);
        $markers=cbia_extract_image_markers_full($html);
        cbia_log_message("Marcadores tras autoinserción: ".count($markers));
    }

    if(!empty($markers)) $markers=array_slice($markers,0,$images_limit);
    cbia_log_message("Límite de imágenes configurado: {$images_limit}. Marcadores a procesar: ".count($markers));

    $featured=null; $generated=0; $searchFrom=0; $pending_count=0;

    foreach($markers as $i=>$mk){
        if(cbia_check_stop_flag()){ cbia_log_message("Detenido (imágenes)."); break; }
        $is_first=($i===0);
        $tipo=cbia_detect_marker_type($html,$mk['full_pos'],$is_first);
        $section_for_prompt=$is_first?'intro':($tipo==='faq'?'faq':($tipo==='conclusion'?'conclusion':'body'));

        $faq_pos_dbg=cbia_find_faq_h2_position($html);
        cbia_log_message("Intentando imagen #".($i+1)." pos_marcador={$mk['full_pos']}"
            ." faq_h2_pos=".($faq_pos_dbg>=0?$faq_pos_dbg:'no')
            ." len_html=".strlen($html)." tipo='{$tipo}' sección='{$section_for_prompt}'"
            ." marcador='".mb_substr(trim($mk['desc']),0,120)."'");

        $img=cbia_generate_section_image($mk['desc'],$section_for_prompt,$title,$is_first,$tipo);
        $alt=$img ? $img['alt'] : cbia_build_img_alt($title,$section_for_prompt,$mk['desc']);

        if($img && isset($img['id'])){
            if($is_first){
                $featured=$img;
                cbia_replace_marker_at($html,$mk['full'],'',$searchFrom);
                cbia_log_message("Imagen 'destacada' generada correctamente (ID {$img['id']}).");
            } else {
                cbia_replace_next_marker_with_img_exact($html,$img['id'],$mk['full'],$alt,$searchFrom,'cbia-banner');
                cbia_log_message("Imagen '{$tipo}' generada e insertada (ID {$img['id']}).");
            }
            $generated++;
            if($generated>=$images_limit) break;
        } else {
            // NUEVO: NO eliminar marcador, se marca como PENDIENTE para posible rellenado posterior
            $placeholder='[IMAGEN_PENDIENTE: '.cbia_sanitize_alt_from_desc($mk['desc']).']';
            cbia_replace_marker_at($html,$mk['full'],$placeholder,$searchFrom);
            $pending_count++;
            cbia_log_message("Imagen fallida para '{$tipo}'. Se marca como PENDIENTE. Desc='".mb_substr(trim($mk['desc']),0,120)."'.");
        }
    }

    // Limpieza de marcadores “normales” sobrantes (si quedaron) -> pasarlos a pendientes
    $left_normals=cbia_extract_image_markers_full($html);
    if(!empty($left_normals)){
        foreach($left_normals as $ln){
            $placeholder='[IMAGEN_PENDIENTE: '.cbia_sanitize_alt_from_desc($ln['desc']).']';
            $pos0=0; cbia_replace_marker_at($html,$ln['full'],$placeholder,$pos0); $pending_count++;
        }
    }

    $faq_section='';
    if(preg_match('/(<h2[^>]*>.*?(preguntas\s+frecuentes|domande\s+frequenti|frequently\s+asked\s+questions|faq).*?<\/h2>.*)$/is',$html,$mfaq))
        $faq_section=$mfaq[1];

    $post_id=cbia_create_post_in_wp($title,$html,$featured,$post_date,cbia_build_faq_json_ld($faq_section));
    if($post_id){
        if($pending_count>0) update_post_meta($post_id,'_cbia_pending_images',$pending_count);
        cbia_log_message("Post ".($post_date?"programado":"publicado")." (modo único) ID: {$post_id}".($pending_count>0?" con {$pending_count} imagen(es) pendientes.":"." ));
        return $post_id;
    }
    return false;
}

/* =========================================================
   ============ DISPATCH (1 por título) =====================
   ========================================================= */
function cbia_create_single_blog_post($title,$post_date=null){
    if(cbia_check_stop_flag()){ cbia_log_message("Detenido por usuario."); return false; }
    if(cbia_post_exists_by_title($title)){ cbia_log_message("El post '{$title}' ya existe. Omitido."); return false; }
    cbia_log_message("Generando post '{$title}' (modo único con marcadores).");
    return cbia_generate_single_with_placeholders_mode($title,$post_date);
}

/* =========================================================
   ============== BATCH con CHECKPOINT ======================
   ========================================================= */
/**
 * Lógica a prueba de 504:
 * - Se construye la cola completa de títulos (filtrando repetidos ya existentes).
 * - Se guarda checkpoint {queue, idx, running=true}.
 * - Cada vez que se crea/programa un post, se incrementa idx y se guarda checkpoint.
 * - Próxima fecha se calcula con _cbia_last_scheduled_at.
 */
function cbia_prepare_queue_from_titles($titles){
    $queue=array();
    foreach($titles as $t){ $t=trim($t); if($t==='') continue; if(cbia_post_exists_by_title($t)) { cbia_log_message("El post '{$t}' ya existe. Omitido (cola)."); continue; } $queue[]=$t; }
    return array_values(array_unique($queue));
}

function cbia_create_all_posts_checkpointed($incoming_titles=null){
    cbia_set_stop_flag(false);
    $settings=cbia_get_settings();
    $interval_days=max(1,intval($settings['publication_interval'] ?? 5));

    // Cargar o crear checkpoint
    $cp=cbia_checkpoint_get();
    if(!$incoming_titles && !empty($cp) && !empty($cp['running']) && isset($cp['queue']) && is_array($cp['queue'])){
        cbia_log_message("Reanudando desde checkpoint: ".count($cp['queue'])." en cola, índice actual ".intval($cp['idx'] ?? 0).".");
        $queue=$cp['queue']; $idx=intval($cp['idx'] ?? 0);
    } else {
        $titles=$incoming_titles ?? cbia_get_titles();
        if(empty($titles)){ cbia_log_message("Sin títulos. Fin."); return; }
        $queue=cbia_prepare_queue_from_titles($titles);
        $idx=0;
        $cp=array('queue'=>$queue,'idx'=>$idx,'created_total'=>0,'running'=>true);
        cbia_checkpoint_save($cp);
        cbia_log_message("Inicio batch con checkpoint: ".count($queue)." título(s) nuevos.");
    }

    if(empty($queue)){ cbia_log_message("No hay títulos nuevos en la cola. Fin."); cbia_checkpoint_clear(); return; }

    foreach($queue as $i=>$title){
        if(cbia_check_stop_flag()){ cbia_log_message("Detenido durante batch (checkpoint)."); break; }
        if($i < $idx) continue; // ya procesado

        // Calcular fecha para este post según última programada/publicada
        $next_dt=cbia_compute_next_datetime($interval_days);
        if($next_dt===''){
            cbia_log_message("Creando entrada '{$title}' publicada ahora (sin primera fecha personalizada o cálculo inmediato).");
            $post_id=cbia_create_single_blog_post($title,null);
            if($post_id){
                // Si se publicó ahora, registramos “última” como ahora
                $now_local=current_time('mysql'); cbia_set_last_scheduled_at($now_local);
                $cp['created_total']++; $cp['idx']=$i+1; cbia_checkpoint_save($cp);
            } else {
                cbia_log_message("No se pudo crear '{$title}'. (Se continúa con el siguiente)");
                $cp['idx']=$i+1; cbia_checkpoint_save($cp);
            }
        } else {
            cbia_log_message("Programando '{$title}' para {$next_dt}.");
            $post_id=cbia_create_single_blog_post($title,$next_dt);
            if($post_id){
                cbia_set_last_scheduled_at($next_dt);
                $cp['created_total']++; $cp['idx']=$i+1; cbia_checkpoint_save($cp);
            } else {
                cbia_log_message("No se pudo crear/programar '{$title}'. (Se continúa con el siguiente)");
                $cp['idx']=$i+1; cbia_checkpoint_save($cp);
            }
        }
    }

    // Fin de cola
    if(intval($cp['idx']) >= count($cp['queue'])){
        cbia_log_message("Proceso finalizado. Entradas nuevas creadas/programadas: {$cp['created_total']}.");
        $cp['running']=false; cbia_checkpoint_save($cp);
        // Opcional: limpiar para próxima vez
        cbia_checkpoint_clear();
    } else {
        cbia_log_message("Ejecución interrumpida. Checkpoint guardado en índice {$cp['idx']} de ".count($cp['queue']).".");
    }
}

/* =========================================================
   ================ TITLES (manual/CSV) =====================
   ========================================================= */
function cbia_get_titles(){
    $settings=cbia_get_settings();
    $mode=$settings['title_input_mode'] ?? 'manual';
    if($mode==='manual'){
        $manual_titles=$settings['manual_titles'] ?? '';
        return array_filter(array_map('trim',explode("\n",$manual_titles)));
    } elseif($mode==='csv'){
        $csv_url=$settings['csv_url'] ?? '';
        if($csv_url){
            $try=0; while($try<2){
                $response=wp_remote_get($csv_url,array('timeout'=>20));
                if(!is_wp_error($response)){ $csv_data=wp_remote_retrieve_body($response); break; }
                $try++; sleep(1);
            }
            if(!is_wp_error($response)){
                $lines=preg_split('/\r\n|\r|\n/',$csv_data);
                $clean=array();
                foreach($lines as $line){
                    $line=trim($line); if($line==='' || stripos($line,'titulo')!==false) continue; $clean[]=$line;
                }
                return array_filter($clean);
            } else { cbia_log_message("Error CSV: ".$response->get_error_message()); }
        } else { cbia_log_message("No se proporcionó URL de CSV."); }
    } else { cbia_log_message("Modo de entrada de títulos no válido."); }
    return array();
}

/* =========================================================
   =================== ACCIONES PRINCIPALES =================
   ========================================================= */
function cbia_run_test_configuration(){
    cbia_log_message("Iniciando prueba...");
    $openai_key=cbia_get_openai_api_key();
    cbia_log_message($openai_key ? "Clave OpenAI encontrada." : "No se encontró clave OpenAI.");
    $s=cbia_get_settings();
    $chain=implode(' → ',cbia_model_fallback_chain($s['openai_model'] ?? 'gpt-4.1-mini'));
    $lim=intval($s['images_limit'] ?? 3); if($lim<1)$lim=1; if($lim>4)$lim=4;
    $first_dt=$s['first_publication_datetime'] ?? '';
    $last_dt=cbia_get_last_scheduled_at() ?: '(sin registros)';
    cbia_log_message("Modelo preferido: ".($s['openai_model'] ?? 'gpt-4.1-mini')." | Fallback: ".$chain);
    cbia_log_message("Variante: ".($s['post_length_variant'] ?? 'medium')." | Límite imágenes: ".$lim." | Primera fecha: ".($first_dt ?: 'inmediata')." | Última programada/publicada: ".$last_dt);
    cbia_log_message("Prueba finalizada.");
}
function cbia_run_generate_blogs(){
    cbia_log_message("Iniciando creación de blogs (checkpoint)…");
    cbia_create_all_posts_checkpointed(null);
    cbia_log_message("Llamada de ejecución finalizada (ver si quedó checkpoint activo).");
}

/* =========================================================
   ============ NUEVO: RELLENAR IMÁGENES PENDIENTES =========
   ========================================================= */
function cbia_fill_pending_images_for_post($post_id,$images_limit=4){
    $post=get_post($post_id); if(!$post) return 0;
    $html=$post->post_content;
    $pending=cbia_extract_pending_markers_full($html);
    $filled=0; $searchFrom=0;

    // Si no hay miniatura, intentamos generar destacada a partir del primer párrafo
    if(!has_post_thumbnail($post_id)){
        $title=get_the_title($post_id);
        $firstp=cbia_first_paragraph_text($html);
        $desc=cbia_sanitize_alt_from_desc($firstp ?: $title);
        $img=cbia_generate_section_image($desc,'intro',$title,true,'destacada-pendiente');
        if($img && isset($img['id'])){
            set_post_thumbnail($post_id,$img['id']);
            cbia_log_message("Miniatura rellenada en post {$post_id} (ID {$img['id']}).");
            $filled++;
        } else {
            cbia_log_message("No se pudo rellenar miniatura en post {$post_id}.");
        }
    }

    if(empty($pending)){ update_post_meta($post_id,'_cbia_pending_images',0); return $filled; }

    $title=get_the_title($post_id);
    foreach($pending as $pm){
        if(cbia_check_stop_flag()) break;
        // Detectar tipo por posición para decidir sección
        $tipo=cbia_detect_marker_type($html,$pm['full_pos'],false);
        $section_for_prompt=($tipo==='faq'?'faq':($tipo==='conclusion'?'conclusion':'body'));
        $img=cbia_generate_section_image($pm['desc'],$section_for_prompt,$title,false,'pendiente-'.$tipo);
        $alt=$img ? $img['alt'] : cbia_build_img_alt($title,$section_for_prompt,$pm['desc']);

        if($img && isset($img['id'])){
            cbia_replace_next_marker_with_img_exact($html,$img['id'],$pm['full'],$alt,$searchFrom,'cbia-banner');
            $filled++;
            if($filled >= $images_limit) break;
        } else {
            // sigue pendiente (mantener el marcador)
        }
    }

    // Guardar cambios y actualizar contador
    if($filled>0){
        // Recontar pendientes
        $left=cbia_extract_pending_markers_full($html);
        $count_left=count($left);
        wp_update_post(array('ID'=>$post_id,'post_content'=>$html));
        update_post_meta($post_id,'_cbia_pending_images',$count_left);
        cbia_log_message("Post {$post_id}: rellenadas {$filled} imagen(es), pendientes ahora={$count_left}.");
    } else {
        cbia_log_message("Post {$post_id}: no se pudo rellenar ninguna imagen.");
    }
    return $filled;
}

function cbia_run_fill_pending_images($limit_posts=10){
    cbia_log_message("Rellenando imágenes pendientes…");
    $args=array(
        'post_type'=>'post',
        'posts_per_page'=>$limit_posts,
        'post_status'=>array('publish','future','draft','pending'),
        'meta_query'=>array(
            array('key'=>'_cbia_created','value'=>'1','compare'=>'='),
            array('key'=>'_cbia_pending_images','value'=>0,'compare'=>'>')
        ),
        'orderby'=>'date',
        'order'=>'DESC'
    );
    $q=new WP_Query($args);
    if(!$q->have_posts()){ cbia_log_message("No hay posts con imágenes pendientes."); return; }
    while($q->have_posts()){
        $q->the_post();
        $pid=get_the_ID();
        $pend=intval(get_post_meta($pid,'_cbia_pending_images',true));
        cbia_log_message("Procesando post {$pid} con {$pend} pendiente(s) declaradas.");
        cbia_fill_pending_images_for_post($pid,4);
        if(cbia_check_stop_flag()){ cbia_log_message("Detenido durante rellenado de pendientes."); break; }
    }
    wp_reset_postdata();
    cbia_log_message("Rellenado de imágenes pendientes finalizado.");
}

/* =========================================================
   =================== CRON OPCIONAL (hourly) ===============
   ========================================================= */
function cbia_cron_setup(){
    $settings=cbia_get_settings();
    $enabled = isset($settings['enable_cron_fill']) ? (bool)$settings['enable_cron_fill'] : false;
    if($enabled){
        if(!wp_next_scheduled('cbia_cron_fill_images')){
            wp_schedule_event(time()+60,'hourly','cbia_cron_fill_images');
        }
    } else {
        $ts=wp_next_scheduled('cbia_cron_fill_images');
        if($ts) wp_unschedule_event($ts,'cbia_cron_fill_images');
    }
}
add_action('updated_option', function($option){
    if($option==='cbia_settings') cbia_cron_setup();
},10,1);

add_action('cbia_cron_fill_images', function(){
    cbia_run_fill_pending_images(6);
});

/* =========================================================
   ======================= ADMIN UI =========================
   ========================================================= */
add_action('admin_menu', function(){
    add_menu_page('Creador Blog IA','Creador Blog IA','manage_options','cbia_settings','cbia_render_admin_page');
});

function cbia_render_admin_page(){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(check_admin_referer('cbia_settings_group-options')){
            $post_unslashed=wp_unslash($_POST);

            if(isset($post_unslashed['cbia_settings'])){
                $raw=$post_unslashed['cbia_settings'];
                $sanitized=array();
                foreach($raw as $key=>$value){
                    $is_textarea=in_array($key,[
                        'prompt_single_all',
                        'prompt_img_intro','prompt_img_body','prompt_img_conclusion','prompt_img_faq',
                        'keywords_to_categories','manual_titles','default_tags'
                    ],true);
                    if($is_textarea) $sanitized[$key]=sanitize_textarea_field($value);
                    else $sanitized[$key]=is_string($value)?sanitize_text_field($value):$value;
                }

                // Normaliza images_limit
                if(isset($sanitized['images_limit'])){ $lim=intval($sanitized['images_limit']); if($lim<1)$lim=1; if($lim>4)$lim=4; $sanitized['images_limit']=$lim; }

                // Parse datetime-local → 'Y-m-d H:i:s' (hora local)
                if(isset($raw['first_publication_datetime_local'])){
                    $raw_local=trim($raw['first_publication_datetime_local']);
                    if($raw_local!==''){
                        $raw_local=str_replace('T',' ',$raw_local).':00';
                        $tz=function_exists('wp_timezone')?wp_timezone():new DateTimeZone(wp_timezone_string());
                        $dt=date_create_from_format('Y-m-d H:i:s',$raw_local,$tz);
                        if($dt instanceof DateTime){ $sanitized['first_publication_datetime']=$dt->format('Y-m-d H:i:s'); }
                    } else { $sanitized['first_publication_datetime']=''; }
                }

                // Checkbox cron
                $sanitized['enable_cron_fill'] = isset($raw['enable_cron_fill']) ? 1 : 0;

                update_option('cbia_settings',$sanitized);
                cbia_cron_setup();
                cbia_log_message("Ajustes guardados.");
            }

            if(isset($post_unslashed['cbia_action'])){
                $action=sanitize_text_field($post_unslashed['cbia_action']);
                if     ($action==='test_config')        cbia_run_test_configuration();
                elseif ($action==='generate_blogs')    { cbia_set_stop_flag(false); cbia_run_generate_blogs(); }
                elseif ($action==='clear_log')         cbia_clear_log();
                elseif ($action==='stop_generation')   cbia_set_stop_flag(true);
                elseif ($action==='fill_pending_imgs') cbia_run_fill_pending_images(10);
                elseif ($action==='clear_checkpoint')  { cbia_checkpoint_clear(); cbia_log_message("Checkpoint limpiado manualmente."); }
            }
        }
    }

    $settings=cbia_get_settings();
    if(!isset($settings['images_limit'])) $settings['images_limit']=3;
    $settings['images_limit']=intval($settings['images_limit']);
    if($settings['images_limit']<1) $settings['images_limit']=1; if($settings['images_limit']>4)$settings['images_limit']=4;
    if(!isset($settings['post_length_variant'])) $settings['post_length_variant']='medium';
    $log=get_option('cbia_activity_log','');

    // Input datetime-local (si hay)
    $first_dt=$settings['first_publication_datetime'] ?? '';
    $first_dt_local_input='';
    if($first_dt!==''){
        $tz=function_exists('wp_timezone')?wp_timezone():new DateTimeZone(wp_timezone_string());
        $dt=date_create_from_format('Y-m-d H:i:s',$first_dt,$tz);
        if($dt) $first_dt_local_input=$dt->format('Y-m-d\TH:i');
    }

    // Estado checkpoint
    $cp=cbia_checkpoint_get();
    $cp_status = (!empty($cp) && !empty($cp['running'])) ? ('EN CURSO | idx '.intval($cp['idx']).' de '.count($cp['queue'])) : 'inactivo';
    $last_dt  = cbia_get_last_scheduled_at() ?: '(sin registros)';

    ?>
    <div class="wrap">
        <h1>Creador Blog IA</h1>
        <form method="post" action="" autocomplete="off">
            <?php settings_fields('cbia_settings_group'); ?>

            <h2>Configuración de OpenAI</h2>
            <table class="form-table">
                <tr><th>Clave API de OpenAI</th>
                    <td><input type="password" name="cbia_settings[openai_api_key]" value="<?php echo esc_attr($settings['openai_api_key'] ?? ''); ?>" autocomplete="off" style="width:420px;" /></td></tr>
                <tr><th>Modelo (recomendado: gpt-4.1-mini)</th>
                    <td>
                        <select name="cbia_settings[openai_model]">
                        <?php
                            $models=cbia_get_supported_models();
                            $current=$settings['openai_model'] ?? 'gpt-4.1-mini';
                            foreach($models as $value=>$label){
                                echo '<option value="'.esc_attr($value).'" '.selected($current,$value,false).'>'.esc_html($label).'</option>';
                            }
                        ?>
                        </select>
                        <p class="description">Responses: 4.1 / 4.1-mini / 4.1-nano / 4o-mini (sin temperatura). Chat: 4o / 4 / 3.5 (con temperatura).</p>
                    </td></tr>
                <tr><th>Temperatura (solo modelos Chat)</th>
                    <td><input type="number" step="0.1" min="0" max="1" name="cbia_settings[openai_temperature]" value="<?php echo esc_attr($settings['openai_temperature'] ?? '0.7'); ?>" /></td></tr>
            </table>

            <h2>Generación (una sola llamada con marcadores)</h2>
            <table class="form-table">
                <tr><th>Variante de Longitud</th>
                    <td>
                        <select name="cbia_settings[post_length_variant]">
                            <option value="short"  <?php selected($settings['post_length_variant'],'short'); ?>>Breve (~1000 palabras)</option>
                            <option value="medium" <?php selected($settings['post_length_variant'],'medium'); ?>>Media (~1600–1800 palabras)</option>
                            <option value="long"   <?php selected($settings['post_length_variant'],'long'); ?>>Extensa (~2200 palabras)</option>
                        </select>
                    </td></tr>
                <tr><th>Límite de Imágenes a Generar</th>
                    <td>
                        <input type="number" min="1" max="4" name="cbia_settings[images_limit]" value="<?php echo esc_attr($settings['images_limit']); ?>" />
                        <p class="description">Entre 1 y 4. La primera imagen va como <strong>destacada</strong> (no se inserta). Las siguientes se incrustan en los marcadores [IMAGEN: ...].</p>
                    </td></tr>
                <tr><th>Prompt Todo en Uno</th>
                    <td><textarea name="cbia_settings[prompt_single_all]" rows="12" cols="100"><?php
echo esc_textarea($settings['prompt_single_all'] ?? "Escribe un POST COMPLETO en HTML para \"{title}\" con objetivo de ~1600–1800 palabras (±10%).
Tono profesional y cercano. Estructura EXACTA, sin añadir otras secciones:
- Párrafo inicial en <p> sin usar la palabra \"Introducción\" (150–180 palabras)
- 3 bloques principales con <h2> y, si aporta, <h3> (200–300 palabras por bloque; usa listas <ul><li>…</li></ul> cuando ayuden a la claridad)
- <h2>Preguntas frecuentes</h2> con 6 FAQs en formato <h3>Pregunta</h3><p>Respuesta</p> (100–130 palabras por respuesta).
Instrucción CRÍTICA: ninguna respuesta debe cortarse y TODAS las respuestas deben terminar en punto final.
Inserta marcadores de imagen con el formato EXACTO:
[IMAGEN: descripción breve, concreta, sin texto ni marcas de agua, estilo realista/editorial]
Reglas de obligado cumplimiento:
• NO uses <h1>.
• NO añadas sección de conclusión ni CTA final.
• NO incluyas <!DOCTYPE>, <html>, <head>, <body>, <script> ni <style>.
• NO enlaces a webs externas (si es necesario, menciona '(enlace interno)' como texto plano).
• Evita redundancias y muletillas.");
                    ?></textarea></td></tr>
            </table>

            <h2>Imagen IA (formato y prompt por sección)</h2>
            <table class="form-table">
                <?php
                $secciones=array('intro'=>'Introducción (destacada)','body'=>'Cuerpo','conclusion'=>'Cierre','faq'=>'FAQ');
                $formatos=array('panoramic'=>'Panorámica (1536x1024)','square'=>'Cuadrada (1024x1024)','vertical'=>'Vertical (1024x1536)','banner'=>'Banner (1536x1024, encuadre amplio + headroom 25–35%)');
                foreach($secciones as $sec_key=>$sec_label){
                    $format_value=$settings['image_format_'.$sec_key] ?? $settings['image_format'] ?? 'panoramic';
                    $prompt_value=$settings['prompt_img_'.$sec_key] ?? '';
                    if($prompt_value===''){
                        if($sec_key==='intro')      $prompt_value='Imagen editorial realista, sin texto, que represente la idea central de "{title}". Iluminación natural, composición limpia.';
                        if($sec_key==='body')       $prompt_value='Imagen realista que ilustre el concepto clave del bloque de "{title}". Sin texto ni logos. Enfoque nítido.';
                        if($sec_key==='conclusion') $prompt_value='Imagen realista que refuerce el resultado/beneficio final de "{title}". Estética coherente con las anteriores.';
                        if($sec_key==='faq')        $prompt_value='Imagen realista de soporte para Preguntas frecuentes de "{title}". Minimalista y clara, sin texto.';
                    }
                    ?>
                    <tr><th>Formato de imagen para <?php echo esc_html($sec_label); ?></th>
                        <td><select name="cbia_settings[image_format_<?php echo esc_attr($sec_key); ?>]">
                            <?php foreach($formatos as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($format_value,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
                        </select>
                        <?php if($format_value==='banner'): ?><p class="description">Nota: banner recomienda <em>toma amplia</em>, headroom 25–35% y márgenes laterales generosos.</p><?php endif; ?>
                        </td></tr>
                    <tr><th>Prompt de imagen para <?php echo esc_html($sec_label); ?></th>
                        <td><textarea name="cbia_settings[prompt_img_<?php echo esc_attr($sec_key); ?>]" rows="2" cols="100"><?php echo esc_textarea($prompt_value); ?></textarea>
                            <p class="description">Si lo dejas vacío, se resume el texto generado para crear un prompt automáticamente. Para banner, se refuerza: toma amplia y headroom 25–35%.</p></td></tr>
                <?php } ?>
            </table>

            <h2>Categorías / Etiquetas</h2>
            <table class="form-table">
                <tr><th>Categorías → Palabras clave</th>
                    <td><textarea name="cbia_settings[keywords_to_categories]" rows="8" cols="100"><?php
                        echo esc_textarea($settings['keywords_to_categories'] ?? "Historia y Cultura:historia, legado, tradicion, cultura
Regiones Productoras:cuba, nicaragua, republica dominicana, habanos
Tendencias y Actualidad:tendencias, actualidad, eventos
Servicios y Experiencias:eventos, bodas, espectaculo, servicio
Accesorios y Maridajes:encendedores, cortapuros, maridaje, ron, whisky");
                    ?></textarea>
                    <p class="description">Formato: <code>Categoría: palabra1, palabra2</code> (una por línea). Si la categoría no existe, se crea automáticamente.</p>
                    </td></tr>
                <tr><th>Etiquetas (máx. 5)</th>
                    <td><textarea name="cbia_settings[default_tags]" rows="2" cols="100"><?php
                        echo esc_textarea($settings['default_tags'] ?? "torcedor de puros, habanos, cigar rolling show, bodas, maridaje");
                    ?></textarea>
                    <p class="description">Se asignan como tags al publicar. WordPress creará las que falten.</p></td></tr>
                <tr><th>Categoría por defecto</th>
                    <td><input type="text" name="cbia_settings[default_category]" value="<?php echo esc_attr($settings['default_category'] ?? 'Noticias'); ?>" /></td></tr>
            </table>

            <h2>Títulos</h2>
            <?php $title_mode=$settings['title_input_mode'] ?? 'manual'; ?>
            <table class="form-table">
                <tr><th>Modo de Entrada</th>
                    <td><label><input type="radio" name="cbia_settings[title_input_mode]" value="manual" <?php checked($title_mode,'manual'); ?>/> Manual</label>
                        &nbsp; <label><input type="radio" name="cbia_settings[title_input_mode]" value="csv" <?php checked($title_mode,'csv'); ?>/> CSV</label></td></tr>
                <tr id="manual-titles-row" <?php if($title_mode!=='manual') echo 'style="display:none;"'; ?>><th>Títulos Manuales</th>
                    <td><textarea name="cbia_settings[manual_titles]" rows="6" cols="100" placeholder="Un título por línea"><?php echo esc_textarea($settings['manual_titles'] ?? "Título de ejemplo"); ?></textarea>
                        <p class="description">Pulsa <strong>Guardar cambios</strong> para almacenar los títulos y luego <strong>Crear Blogs</strong>.</p>
                    </td></tr>
                <tr id="csv-url-row" <?php if($title_mode!=='csv') echo 'style="display:none;"'; ?>><th>URL CSV</th>
                    <td><input type="text" name="cbia_settings[csv_url]" value="<?php echo esc_attr($settings['csv_url'] ?? ''); ?>" style="width:420px;" /></td></tr>
            </table>

            <h2>Automatización</h2>
            <table class="form-table">
                <tr><th>Intervalo de Publicación (días)</th>
                    <td><input type="number" min="1" name="cbia_settings[publication_interval]" value="<?php echo esc_attr($settings['publication_interval'] ?? '5'); ?>" /></td></tr>
                <tr><th>Primera publicación (opcional)</th>
                    <td>
                        <input type="datetime-local" name="cbia_settings[first_publication_datetime_local]" value="<?php echo esc_attr($first_dt_local_input); ?>" />
                        <p class="description">Déjalo vacío para publicar la primera entrada <strong>inmediatamente</strong>. Si defines fecha/hora, la primera nueva se <strong>programará</strong> entonces; las siguientes se espaciarán por el intervalo.</p>
                    </td></tr>
                <tr><th>Rellenar pendientes con WP-Cron (cada hora)</th>
                    <td>
                        <label><input type="checkbox" name="cbia_settings[enable_cron_fill]" <?php checked(!empty($settings['enable_cron_fill'])); ?>/> Activar cron para rellenar imágenes pendientes</label>
                        <p class="description">Si está activo, cada hora intentará añadir imágenes en posts con marcadores <code>[IMAGEN_PENDIENTE: ...]</code> y sin miniatura.</p>
                    </td></tr>
            </table>

            <?php wp_nonce_field('cbia_settings_group-options'); ?>

            <p>
                <button type="submit" class="button button-primary" name="cbia_action" value="save_changes">Guardar cambios</button>
            </p>

            <h2>Acciones</h2>
            <p>
                <button type="submit" class="button button-secondary" name="cbia_action" value="test_config">Probar Configuración</button>
                <button type="submit" class="button button-primary" name="cbia_action" value="generate_blogs">Crear Blogs (con reanudación)</button>
                <button type="submit" class="button" name="cbia_action" value="stop_generation" style="margin-left:8px;background:#b70000;color:#fff;">Detener</button>
                <button type="submit" class="button button-secondary" name="cbia_action" value="fill_pending_imgs" style="margin-left:8px;">Rellenar imágenes pendientes</button>
                <button type="submit" class="button" name="cbia_action" value="clear_checkpoint" style="margin-left:8px;">Limpiar Checkpoint</button>
                <button type="submit" class="button" name="cbia_action" value="clear_log" style="margin-left:8px;">Limpiar Log</button>
            </p>

            <h2>Estado</h2>
            <table class="form-table">
                <tr><th>Checkpoint</th><td><code><?php echo esc_html($cp_status); ?></code></td></tr>
                <tr><th>Última programada/publicada</th><td><code><?php echo esc_html($last_dt); ?></code></td></tr>
            </table>

            <h2>Log de Actividades</h2>
            <textarea id="activity-log" rows="14" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea($log); ?></textarea>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const manualTitlesRow = document.getElementById('manual-titles-row');
        const csvUrlRow = document.getElementById('csv-url-row');
        const radios = document.querySelectorAll('input[name="cbia_settings[title_input_mode]"]');
        if (manualTitlesRow && csvUrlRow && radios) {
            radios.forEach(r => r.addEventListener('change', function(){
                if (this.value === 'manual') { manualTitlesRow.style.display=''; csvUrlRow.style.display='none'; }
                else { manualTitlesRow.style.display='none'; csvUrlRow.style.display=''; }
            }));
        }
        const logBox = document.getElementById('activity-log');
        function refreshLog() {
            fetch(ajaxurl + '?action=cbia_get_log', { credentials: 'same-origin' })
                .then(resp => resp.json()).then(data => {
                    if (data.success && logBox) { logBox.value = data.data; logBox.scrollTop = logBox.scrollHeight; }
                });
        }
        setInterval(refreshLog, 3000);
    });
    </script>
<?php
}

/* ========================= AJAX LOG ========================= */
add_action('wp_ajax_cbia_get_log', function(){
    if(!current_user_can('manage_options')) wp_send_json_error('No autorizado');
    $log=get_option('cbia_activity_log','');
    wp_send_json_success($log);
});

/* ------------------------- FIN v5.7.9 ------------------------- */
