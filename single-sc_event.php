<?php get_header(); ?>

<?php

	$event_id_     = get_the_ID();
	$participants  = get_post_meta( $event_id_, 'participants', true );
	$inscription   = get_post_meta( $event_id_, 'inscription', true );
	$feat_img      = get_post_meta( $event_id_, 'feat_img', true );

?>

<?php if ( ! function_exists( 'elementor_theme_do_location' ) || ! elementor_theme_do_location( 'single' ) ) { ?>

	<div id="primary" class="content-area blog-single">

		<div id="content" class="site-content" role="main">

			<?php while ( have_posts() ) : the_post(); ?>

<div>

	<?php
	$thumb_url = "";
	if ( has_post_thumbnail() ) {
		$thumb_url = wp_get_attachment_url( get_post_thumbnail_id( $post->ID ), 'full' );
	}
	?>

    <?php

    $meta_class = '';
    if ( ! Shopkeeper_Opt::getOption( 'post_meta_author', true ) && ! Shopkeeper_Opt::getOption( 'post_meta_date', true ) && ! Shopkeeper_Opt::getOption( 'post_meta_categories', true ) ) {
    	$meta_class = 'no-meta';
    }

	$single_post_header_thumb_class = "";
	$single_post_header_thumb_style = "";

	if ( is_single() && has_post_thumbnail() && ! post_password_required() ) {

		if ( get_post_meta( $post->ID, 'post_featured_image_meta_box_check', true ) ) {
			$post_featured_image_option = get_post_meta( $post->ID, 'post_featured_image_meta_box_check', true );
		} else {
			$post_featured_image_option = "on";
		}

		if ( ( isset( $post_featured_image_option ) ) && ( $post_featured_image_option == "on" && ( $feat_img !== 'No' ) ) ) {
			$single_post_header_thumb_class = "with-thumb";
			$single_post_header_thumb_style = 'background-image:url(' . $thumb_url . ')';
		} else {
			$single_post_header_thumb_class = "";
			$single_post_header_thumb_style = "";
		}

	}

	// --- Tossa Cycling: per-event header overrides (meta) ---
	$tc_title_mode            = get_post_meta( $event_id_, 'tc_header_title_mode', true );
	$tc_custom_title          = get_post_meta( $event_id_, 'tc_header_title_custom', true );
	$tc_subtitle              = get_post_meta( $event_id_, 'tc_header_subtitle', true );
	$tc_show_divider          = (bool) get_post_meta( $event_id_, 'tc_header_show_divider', true );
	$tc_show_back_link        = (bool) get_post_meta( $event_id_, 'tc_header_show_back_link', true );
	$tc_back_link_url         = get_post_meta( $event_id_, 'tc_header_back_link_url', true );
	$tc_back_link_label       = get_post_meta( $event_id_, 'tc_header_back_link_label', true );
	$tc_details_position      = get_post_meta( $event_id_, 'tc_header_details_position', true );
	$tc_show_shopkeeper_meta  = (bool) get_post_meta( $event_id_, 'tc_header_show_shopkeeper_meta', true );
	// --- NEW: per-event sizing (CSS variables) ---
	$tc_logo_max_width = absint( get_post_meta( $event_id_, 'tc_header_logo_max_width', true ) ); // px
	$tc_title_max_size = absint( get_post_meta( $event_id_, 'tc_header_title_max_size', true ) ); // px
	$tc_subtitle_size         = absint( get_post_meta( $event_id_, 'tc_header_subtitle_size', true ) );        // px
	$tc_header_padding_bottom = absint( get_post_meta( $event_id_, 'tc_header_padding_bottom', true ) );      // px
	$tc_details_bottom        = absint( get_post_meta( $event_id_, 'tc_header_details_bottom', true ) );      // px
	$tc_logo_margin_bottom    = absint( get_post_meta( $event_id_, 'tc_header_logo_margin_bottom', true ) );  // px


	$tc_header_vars_parts = array();

	if ( $tc_logo_max_width > 0 ) {
		$tc_header_vars_parts[] = '--tc-logo-max:' . $tc_logo_max_width . 'px';
	}
	if ( $tc_title_max_size > 0 ) {
		$tc_header_vars_parts[] = '--tc-title-max:' . $tc_title_max_size . 'px';
	}
	if ( $tc_subtitle_size > 0 ) {
	  $tc_header_vars_parts[] = '--tc-subtitle-max:' . $tc_subtitle_size . 'px';
	}
	if ( $tc_header_padding_bottom > 0 ) {
	  $tc_header_vars_parts[] = '--tc-header-pad-b:' . $tc_header_padding_bottom . 'px';
	}
	if ( $tc_details_bottom > 0 ) {
	  $tc_header_vars_parts[] = '--tc-details-bottom:' . $tc_details_bottom . 'px';
	}
	if ( $tc_logo_margin_bottom > 0 ) {
	  $tc_header_vars_parts[] = '--tc-logo-mb:' . $tc_logo_margin_bottom . 'px';
	}

	$tc_header_vars = implode( ';', $tc_header_vars_parts );
	if ( ! empty( $tc_header_vars ) ) {
		$tc_header_vars .= ';';
	}
	if ( empty( $tc_title_mode ) ) {
		$tc_title_mode = 'default'; // default | custom | hide
	}
	if ( empty( $tc_details_position ) ) {
		$tc_details_position = 'content'; // content | header
	}
	if ( empty( $tc_back_link_url ) ) {
		$tc_back_link_url = '/salidas_guiadas-listado';
	}
	if ( empty( $tc_back_link_label ) ) {
		$tc_back_link_label = '[:en]<< All events[:es]<< Todos los eventos[:]';
	}

	// qTranslate-X friendly output (if qTranslate is active)
	if ( function_exists( 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage' ) ) {
		$tc_custom_title    = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $tc_custom_title );
		$tc_subtitle        = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $tc_subtitle );
		$tc_back_link_label = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $tc_back_link_label );
	}

	$tc_logo_html = function_exists( 'tc_sc_event_get_logo_html' ) ? tc_sc_event_get_logo_html( $event_id_ ) : '';
	?>

    <div class="header single-post-header <?php echo esc_attr( $single_post_header_thumb_class ); ?>"<?php echo ! empty( $tc_header_vars ) ? ' style="' . esc_attr( $tc_header_vars ) . '"' : ''; ?>>


		<?php // BACK LINK (moved outside .title so it shows with and without featured header image) ?>
		<?php if ( $tc_show_back_link ) : ?>
			<div class="tc-event-header-back">
				<a href="<?php echo esc_url( $tc_back_link_url ); ?>"><?php echo wp_kses_post( $tc_back_link_label ); ?></a>
			</div>
		<?php endif; ?>

		<?php if ( $single_post_header_thumb_class == "with-thumb" ) : ?>
			<div class="single-post-header-bkg" style="<?php echo esc_attr( $single_post_header_thumb_style ); ?>"></div>
			<div class="single-post-header-overlay"></div>
		<?php endif; ?>

		<div class="row">
            <div class="xxlarge-5 xlarge-8 large-12 large-centered columns">

				<div class="title tc-event-header-title">

					<?php if ( ! empty( $tc_logo_html ) ) : ?>
						<div class="tc-event-header-logo">
							<?php echo $tc_logo_html; ?>
						</div>
					<?php endif; ?>

					<?php if ( 'hide' !== $tc_title_mode ) : ?>
						<h2 class="entry-title">
							<?php
							if ( 'custom' === $tc_title_mode && ! empty( $tc_custom_title ) ) {
								echo wp_kses_post( $tc_custom_title );
							} else {
								the_title();
							}
							?>
						</h2>
					<?php endif; ?>

					<?php if ( ! empty( $tc_subtitle ) ) : ?>
						<?php if ( $tc_show_divider ) : ?>
							<div class="tc-event-header-divider"></div>
						<?php endif; ?>
						<div class="tc-event-header-subtitle"><?php echo wp_kses_post( $tc_subtitle ); ?></div>
					<?php elseif ( $tc_show_shopkeeper_meta ) : ?>
						<div class="post_meta <?php echo esc_attr( $meta_class ); ?>"> <?php shopkeeper_entry_meta(); ?></div>
					<?php endif; ?>
				</div>

				<?php
				$tc_has_header_thumb = ( $single_post_header_thumb_class === 'with-thumb' );
				?>

				<?php if ( 'header' === $tc_details_position && $tc_has_header_thumb && function_exists( 'tc_sc_event_render_details_bar' ) ) : ?>
					<?php echo tc_sc_event_render_details_bar( $event_id_ ); ?>
				<?php endif; ?>

            </div>
        </div>
    </div>

</div><!--.intro-effect-fadeout-->

<div id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

    <div class="row">

		<div class="xxlarge-8 xlarge-10 large-12 large-centered columns with-sidebar">

			<div class="row">

				<div class="large-12 columns">

					<div class="entry-content blog-single">
						<?php the_content(); ?>
						<?php wp_link_pages(); ?>
					</div><!-- .entry-content -->

					<?php if ( is_single() ) : ?>
					<footer class="entry-meta">
						<div class="post_tags"> <?php echo shopkeeper_entry_tags(); ?></div>
					</footer><!-- .entry-meta -->
					<?php endif; ?>

				</div><!-- .columns-->

			</div><!-- .row-->

        </div><!-- .columns -->

    </div><!-- .row -->

</div><!-- #post -->

				<?php
					if ( comments_open() || '0' != get_comments_number() ) {
						comments_template();
					}
				?>

			<?php endwhile; // end of the loop. ?>

		</div><!-- #content -->

    </div><!-- #primary -->

<?php } ?>

<?php get_footer(); ?>