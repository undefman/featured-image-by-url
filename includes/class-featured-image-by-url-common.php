<?php
/**
 * Common functions class for Featured Image by URL.
 *
 * @link       http://knawat.com/
 * @since      1.0.0
 *
 * @package    Featured_Image_By_URL
 * @subpackage Featured_Image_By_URL/includes
 */
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Featured_Image_By_URL_Common {

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		
		add_action( 'init', array( $this, 'knawatfibu_set_thumbnail_id_true' ) );
		add_filter( 'post_thumbnail_html', array( $this, 'knawatfibu_overwrite_thumbnail_with_url' ), 999, 5 );

		if( !is_admin() ){
			add_filter('wp_get_attachment_image_src', array( $this, 'knawatfibu_replace_attachment_image_src' ), 10, 4 );
			add_filter( 'woocommerce_product_get_gallery_image_ids', array( $this, 'knawatfibu_set_customized_gallary_ids' ), 99, 2 );
		}

		$options = get_option( KNAWATFIBU_OPTIONS );
		$resize_images = isset( $options['resize_images'] ) ? $options['resize_images']  : false;
		if( !$resize_images ){
			add_filter( 'knawatfibu_user_resized_images', '__return_false' );
		}
	}

	/**
	 * add filters for set '_thubmnail_id' true if post has external featured image.
	 *
	 * @since 1.0
	 * @return void
	 */
	function knawatfibu_set_thumbnail_id_true(){
		global $knawatfibu;
		foreach ( $knawatfibu->admin->knawatfibu_get_posttypes() as $post_type ) {
			add_filter( "get_{$post_type}_metadata", array( $this, 'knawatfibu_set_thumbnail_true' ), 10, 4 );
		}
	}

	/**
	 * Set '_thubmnail_id' true if post has external featured image.
	 *
	 * @since 1.0
	 * @return void
	 */
	function knawatfibu_set_thumbnail_true( $value, $object_id, $meta_key, $single ){

		global $knawatfibu;
		$post_type = get_post_type( $object_id );
		if( $this->knawatfibu_is_disallow_posttype( $post_type ) ){
			return $value;
		}

		if ( $meta_key == '_thumbnail_id' ){
			$image_data = $knawatfibu->admin->knawatfibu_get_image_meta( $object_id );
			if ( isset( $image_data['img_url'] ) && $image_data['img_url'] != '' ){
				if( $post_type == 'product' ){
					return '_knawatfibu_fimage_url__' . $object_id;	
				}
				return true;
			}
		}
		return $value;
	}

	/**
	 * Get Overwrited Post Thumbnail HTML with External Image URL
	 *
	 * @since 1.0
	 * @return string
	 */
	function knawatfibu_overwrite_thumbnail_with_url( $html, $post_id, $post_image_id, $size, $attr ){

		global $knawatfibu;
		if( $this->knawatfibu_is_disallow_posttype( get_post_type( $post_id ) ) ){
			return $html;
		}

		if( is_singular( 'product' ) && 'product' == get_post_type( $post_id ) && 'shop_single' == $size ){

			return $html;
		}
		
		$image_data = $knawatfibu->admin->knawatfibu_get_image_meta( $post_id );
		
		if( !empty( $image_data['img_url'] ) ){
			$image_url 		= $image_data['img_url'];

			// Run Photon Resize Magic.
			if( apply_filters( 'knawatfibu_user_resized_images', true ) ){
				$image_url = $this->knawatfibu_resize_image_on_the_fly( $image_url, $size );	
			}

			$image_alt	= ( $image_data['img_alt'] ) ? 'alt="'.$image_data['img_alt'].'"' : '';
			$classes 	= 'external-img wp-post-image ';
			$classes   .= ( isset($attr['class']) ) ? $attr['class'] : '';
			$style 		= ( isset($attr['style']) ) ? 'style="'.$attr['style'].'"' : '';

			$html = sprintf('<img src="%s" %s class="%s" %s />', 
							$image_url, $image_alt, $classes, $style);
		}
		return $html;
	}

	/**
	 * Get Resized Image URL based on main Image URL & size
	 *
	 * @since 1.0
	 * @param string $image_url Full image URL
	 * @param string $size      Image Size
	 *
	 * @return string
	 */
	public function knawatfibu_resize_image_on_the_fly( $image_url, $size = 'full' ){
		if( $size == 'full' || empty( $image_url )){
			return $image_url;
		}

		if( !class_exists( 'Jetpack_PostImages' ) || !defined( 'JETPACK__VERSION' ) ){
			return $image_url;
		}

		$image_size = $this->knawatfibu_get_image_size( $size );
		
		if( !empty( $image_size ) && !empty( $image_size['width'] ) ){
			$width = (int) $image_size['width'];
			$height = (int) $image_size['height'];

			if ( $width < 1 || $height < 1 ) {
				return $image_url;
			}

			// If WPCOM hosted image use native transformations
			$img_host = parse_url( $image_url, PHP_URL_HOST );
			if ( '.files.wordpress.com' == substr( $img_host, -20 ) ) {
				return add_query_arg( array( 'w' => $width, 'h' => $height, 'crop' => 1 ), set_url_scheme( $image_url ) );
			}

			// Use Photon magic
			if( function_exists( 'jetpack_photon_url' ) ) {
				if( isset( $image_size['crop'] ) && $image_size['crop'] == 1 ){
					return jetpack_photon_url( $image_url, array( 'resize' => "$width,$height" ) );
				}else{
					return jetpack_photon_url( $image_url, array( 'fit' => "$width,$height" ) );
				}
				
			}
			//$image_url = Jetpack_PostImages::fit_image_url ( $image_url, $image_size['width'], $image_size['height'] );
		}
		
		//return it.
		return $image_url;
	}

	/**
	 * Get size information for all currently-registered image sizes.
	 *
	 * @global $_wp_additional_image_sizes
	 * @uses   get_intermediate_image_sizes()
	 * @return array $sizes Data for all currently-registered image sizes.
	 */
	function knawatfibu_get_image_sizes() {
		global $_wp_additional_image_sizes;

		$sizes = array();

		foreach ( get_intermediate_image_sizes() as $_size ) {
			if ( in_array( $_size, array('thumbnail', 'medium', 'medium_large', 'large') ) ) {
				$sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
				$sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
				$sizes[ $_size ]['crop']   = (bool) get_option( "{$_size}_crop" );
			} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
				$sizes[ $_size ] = array(
					'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
					'height' => $_wp_additional_image_sizes[ $_size ]['height'],
					'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
				);
			}
		}

		return $sizes;
	}

	/**
	 * Get WC gallary data.
	 *
	 * @since 1.0
	 * @return void
	 */
	function knawatfibu_get_wcgallary_meta( $post_id ){
		
		$image_meta  = array();

		$gallary_images = get_post_meta( $post_id, KNAWATFIBU_WCGALLARY, true );
		
		if( !is_array( $gallary_images ) && $gallary_images != '' ){
			$gallary_images = explode( ',', $gallary_images );
			if( !empty( $gallary_images ) ){
				$gallarys = array();
				foreach ($gallary_images as $gallary_image ) {
					$gallary = array();
					$gallary['url'] = $gallary_image;
					$imagesizes = getimagesize( $gallary_image );
					$gallary['width'] = isset( $imagesizes[0] ) ? $imagesizes[0] : '';
					$gallary['height'] = isset( $imagesizes[1] ) ? $imagesizes[1] : '';
					$gallarys[] = $gallary;
				}
				$gallary_images = $gallarys;
				update_post_meta( $post_id, KNAWATFIBU_WCGALLARY, $gallary_images );
				return $gallary_images;
			}
		}else{
			if( !empty( $gallary_images ) ){
				$need_update = false;
				foreach ($gallary_images as $key => $gallary_image ) {
					if( !isset( $gallary_image['width'] ) && isset( $gallary_image['url'] ) ){
						$imagesizes1 = getimagesize( $gallary_image['url'] );
						$gallary_images[$key]['width'] = isset( $imagesizes1[0] ) ? $imagesizes1[0] : '';
						$gallary_images[$key]['height'] = isset( $imagesizes1[1] ) ? $imagesizes1[1] : '';
						$need_update = true;
					}
				}
				if( $need_update ){
					update_post_meta( $post_id, KNAWATFIBU_WCGALLARY, $gallary_images );
				}
				return $gallary_images;
			}	
		}
		
		
		return $gallary_images;
	}

	/**
	 * Get fake product gallary ids if url gallery values are there.
	 *
	 * @param  string $value Default product gallery ids
	 * @param  object $product WC Product
	 *
	 * @return bool|array $value modified gallary ids.
	 */
	function knawatfibu_set_customized_gallary_ids( $value, $product ){

		if( $this->knawatfibu_is_disallow_posttype( 'product') ){
			return $value;
		}

		$product_id = $product->get_id();
		if( empty( $product_id ) ){
			return $value;
		}
		$gallery_images = $this->knawatfibu_get_wcgallary_meta( $product_id );
		if( !empty( $gallery_images ) ){
			$i = 0;
			foreach ( $gallery_images as $gallery_image ) {
				$gallery_ids[] = '_knawatfibu_wcgallary__'.$i.'__'.$product_id;
				$i++;
			}
			return $gallery_ids;
		}
		return $value;
	}

	/**
	 * Get image src if attachement id contains '_knawatfibu_wcgallary' or '_knawatfibu_fimage_url'
	 *
	 * @uses   get_image_sizes()
	 * @param  string $image Image Src
	 * @param  int $attachment_id Attachment ID
	 * @param  string $size Size
	 * @param  string $icon Icon
	 *
	 * @return bool|array $image Image Src
	 */
	function knawatfibu_replace_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		global $knawatfibu;
		if( false !== strpos( $attachment_id, '_knawatfibu_wcgallary' ) ){
			$attachment = explode( '__', $attachment_id );
			$image_num  = $attachment[1];
			$product_id = $attachment[2];
			if( $product_id > 0 ){
				
				$gallery_images = $knawatfibu->common->knawatfibu_get_wcgallary_meta( $product_id );;
				if( !empty( $gallery_images ) ){
					if( !isset( $gallery_images[$image_num]['url'] ) ){
						return false;
					}
					$url = $gallery_images[$image_num]['url'];
					
					if( apply_filters( 'knawatfibu_user_resized_images', true ) ){
						$url = $knawatfibu->common->knawatfibu_resize_image_on_the_fly( $url, $size );	
					}
					$image_size = $knawatfibu->common->knawatfibu_get_image_size( $size );
					
			        if ($url) {
			        	if( $image_size ){
			        		return array(
				                $url,
				                $image_size['width'],
				                $image_size['height'],
				                $image_size['crop'],
				            );
			        	}else{
			        		if( $gallery_images[$image_num]['width'] != '' && $gallery_images[$image_num]['width'] > 0 ){
			        			return array( $url, $gallery_images[$image_num]['width'], $gallery_images[$image_num]['height'], false );
			        		}else{
			        			return array( $url, 800, 600, false );
			        		}
			        		
				       	}
			        }
				}
			}
		}

		if( false !== strpos( $attachment_id, '_knawatfibu_fimage_url' ) && in_array( $size, array( 'shop_thumbnail', 'shop_single', 'full' ) ) ){
			$attachment = explode( '__', $attachment_id );
			$product_id  = $attachment[1];
			if( $product_id > 0 ){

				$image_data = $knawatfibu->admin->knawatfibu_get_image_meta( $product_id, true );

				if( !empty( $image_data['img_url'] ) ){

					$image_url = $image_data['img_url'];
					$width = isset( $image_data['width'] ) ? $image_data['width'] : '';
					$height = isset( $image_data['height'] ) ? $image_data['height'] : '';

					// Run Photon Resize Magic.
					if( apply_filters( 'knawatfibu_user_resized_images', true ) ){
						$image_url = $knawatfibu->common->knawatfibu_resize_image_on_the_fly( $image_url, $size );	
					}

					$image_size = $knawatfibu->common->knawatfibu_get_image_size( $size );
					
			        if ($image_url) {
			        	if( $image_size ){
			        		return array(
				                $image_url,
				                $image_size['width'],
				                $image_size['height'],
				                $image_size['crop'],
				            );
			        	}else{
			        		if( $width != '' && $height != '' ){
			        			return array( $image_url, $width, $height, false );
			        		}
			        		return array( $image_url, 800, 600, false );
				       	}
			        }
				}
			}
		}

	    return $image;
	}

	/**
	 * Get size information for a specific image size.
	 *
	 * @uses   get_image_sizes()
	 * @param  string $size The image size for which to retrieve data.
	 * @return bool|array $size Size data about an image size or false if the size doesn't exist.
	 */
	function knawatfibu_get_image_size( $size ) {
		$sizes = $this->knawatfibu_get_image_sizes();

		if ( isset( $sizes[ $size ] ) ) {
			return $sizes[ $size ];
		}

		return false;
	}

	/**
	 * Get if Is current posttype is active to show featured image by url or not.
	 *
	 * @param  string $posttype Post type
	 * @return bool
	 */
	function knawatfibu_is_disallow_posttype( $posttype ) {

		$options = get_option( KNAWATFIBU_OPTIONS );
		$disabled_posttypes = isset( $options['disabled_posttypes'] ) ? $options['disabled_posttypes']  : array();

		return in_array( $posttype, $disabled_posttypes );
	}
}