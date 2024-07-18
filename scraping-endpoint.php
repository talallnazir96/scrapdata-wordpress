<?php 
add_action('rest_api_init', 'scrap_data_api');
function scrap_data_api() {
    register_rest_route(
        'api', 'post/motors/listing-endpoint',
        array(
            'methods'  => 'POST',
            'callback' => 'scrap_data',
        )
    );
}
function scrap_data($request){
    require_once( ABSPATH . 'wp-admin/includes/file.php' );

    if(isset($request)) {
        $data = array(
            'post_title' => $request['title'],
            'post_type' => 'listings',
            'post_author' => '1',
            'post_status' => 'publish',
            'post_content' => $request['description']
        );
        $post_id = wp_insert_post( $data, true );
        
        if ( ! is_wp_error( $post_id ) ) {

            update_post_meta( $post_id, 'stock_number', $request['vehicle_detail']['lot_number'] );
            update_post_meta( $post_id, 'vin_number', $request['vehicle_detail']['vin'] );

            $loc = $request['sale_information']['sublot_location'].', '.$request['sale_information']['sale_location'];

            update_post_meta( $post_id, 'stm_car_location', $loc);
            update_post_meta( $post_id, 'price', $request['vehicle_detail']['estimated_retail_value'] );

            foreach($request['options'] as $key => $value) {

                $taxonomy = str_replace("_","-",$key);
                $slug = strtolower(str_replace(" ","-",$value));
                $term = get_term_by('slug', $slug, $taxonomy);
    
                if(!empty($term)) {
                    update_post_meta($post_id, $taxonomy, $slug);
                }
                else {
                    wp_insert_term($value, $taxonomy, array('description' => '', 'slug' => $slug, 'parent' => 0));
                    update_post_meta($post_id, $taxonomy, $slug);
                }
                
            }
        }
        $attachment_ids = array();
        foreach($request['images'] as $k => $v) {
            $temp_file = download_url( $v );

            if( is_wp_error( $temp_file ) ) {
                return false;
            }
            $file = array(
                'name'     => basename( $v ),
                'type'     => mime_content_type( $temp_file ),
                'tmp_name' => $temp_file,
                'size'     => filesize( $temp_file ),
            );
            
            $sideload = wp_handle_sideload(
                $file,
                array(
                    'test_form'   => false // no needs to check 'action' parameter
                )
            );
        
            if( ! empty( $sideload[ 'error' ] ) ) {
                // you may return error message if you want
                return false;
            }
        
            // it is time to add our uploaded image into WordPress media library
            $attachment_id = wp_insert_attachment(
                array(
                    'guid'           => $sideload[ 'url' ],
                    'post_mime_type' => $sideload[ 'type' ],
                    'post_title'     => basename( $sideload[ 'file' ] ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                    'post_parent'      => $post_id
                ),
                $sideload[ 'file' ]
            );
        
            if( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                return false;
            }
        
            // update medatata, regenerate image sizes
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
            wp_update_attachment_metadata(
                $attachment_id,
                wp_generate_attachment_metadata( $attachment_id, $sideload[ 'file' ] )
            );
        
            $attachment_ids[$k] = $attachment_id;
        }
        ksort( $attachment_ids );
		if ( ! empty( $attachment_ids ) ) {
			update_post_meta( $post_id, '_thumbnail_id', reset( $attachment_ids ) );
			array_shift( $attachment_ids );
		}

		update_post_meta( $post_id, 'gallery', $attachment_ids );
        $response = array(
            "message" => "Vehicle has been added successfully",
            "post_id" => $post_id,
            "response" => true
        );
        return json_encode($response);
    }
    else {
        $response = array(
            "message" => "Error Occured while fetching data",
            "post_id" => NULL,
            "response" => false
        );
        return json_encode($response);
    }
    exit;
}