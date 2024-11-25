<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       taher.atashbar@gmail.com
 * @since      1.0.0
 *
 * @package    WC_Conditions
 * @subpackage WC_Conditions/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WC_Conditions
 * @subpackage WC_Conditions/admin
 * @author     Taher Atashbar <taher.atashbar@gmail.com>
 */
class WCCS_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WCCS_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	private $loader;

	/**
	 * Service container of the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var   WCCS_Service_Manager
	 */
	private $services;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 * @param string      $plugin_name The name of this plugin.
	 * @param string      $version     The version of this plugin.
	 * @param WCCS_Loader $loader
	 */
	public function __construct( $plugin_name, $version, WCCS_Loader $loader, WCCS_Service_Manager $services ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->loader      = $loader;
		$this->services    = $services;

		$this->load_dependencies();
		$this->handle_offers();
	}

	/**
	 * Load dependencies required in admin area.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	protected function load_dependencies() {
		/**
		 * The class responsible for Ajax operations.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-ajax.php';
		/**
		 * The controller class of admin area.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-controller.php';
		/**
		 * The class responsible for outputting html elements in pages.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-html-element.php';
		/**
		 * The class responsible for creating all admin menus of the plugin.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-menu.php';
		/**
		 * The class responsible for admin assets.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-assets.php';
		/**
		 * The class responsible for showing admin notices.
		 */
		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-notices.php';

		require_once plugin_dir_path( __FILE__ ) . 'class-wccs-admin-select-data-provider.php';
		require_once dirname( __FILE__ ) . '/class-wccs-admin-conditions-hooks.php';
		require_once dirname( __FILE__ ) . '/class-wccs-admin-order-hooks.php';
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   public
	 */
	public function define_hooks() {
		// Menu hooks.
		$menu = new WCCS_Admin_Menu( $this->loader );
		// Admin notices.
		WCCS()->WCCS_Admin_Notices->init();
		// Ajax Operations.
		new WCCS_Admin_Ajax( $this->loader );
		// Admin Assets.
		$admin_assets = new WCCS_Admin_Assets( $this->loader, $menu );
		$admin_assets->init_hooks();

		$conditions_hooks = new WCCS_Admin_Conditions_Hooks( $this->loader );
		$conditions_hooks->enable_hooks();

		$order_hooks = new WCCS_Admin_Order_Hooks( $this->loader );
		$order_hooks->enable_hooks();

		// Cache Clear Hooks.
		WCCS()->WCCS_Clear_Cache->enable_hooks();

		// Plugin links.
		$this->loader->add_filter( 'plugin_row_meta', $this, 'plugin_row_meta_links', 10, 2 );
	}

	/**
	 * Plugin row meta links
	 * This function adds additional links below the plugin in admin plugins page.
	 *
	 * @since  1.0.0
	 * @param  array  $links 	The array having default links for the plugin.
	 * @param  string $file 	The name of the plugin file.
	 * @return array  $links 	Plugin default links and specific links.
	 */
	public function plugin_row_meta_links( $links, $file ) {
		if ( false !== strpos( $file, 'easy-woocommerce-discounts.php' ) ) {
			$plugin_links = array(
				'<a href="https://www.asanaplugins.com/product/advanced-woocommerce-dynamic-pricing-discounts/?utm_source=easy-woocommerce-discounts-free&utm_campaign=easy-woocommerce-discounts&utm_medium=link" target="_blank" onMouseOver="this.style.color=\'#55ce5a\'" onMouseOut="this.style.color=\'#39b54a\'" style="color: #39b54a; font-weight: bold;">' . esc_html__( 'Go Pro', 'easy-woocommerce-discounts' ) . '</a>',
			);
			$links = array_merge( $links, $plugin_links );
		}

		return $links;
	}

	protected function add_offer_notice( $offer_name, $start_date, $end_date, $message, $button_label, $button_url, $button_color = '#0071a1' ) {
		$name = 'wccs_' . $offer_name . '_' . date( 'Y' );
		if ( (int) get_option( $name . '_added' ) ) {
			// Is the offer expired.
			if ( time() > strtotime( $end_date . ' 23:59:59' ) ) {
				\WC_Admin_Notices::remove_notice( $name );
				delete_option( $name . '_added' );
			}
			return;
		}

		if ( \WC_Admin_Notices::has_notice( $name ) ) {
			return;
		}

		// Is the offer applicable.
		if (
			time() < strtotime( $start_date . ' 00:00:00' ) ||
			time() > strtotime( $end_date . ' 23:59:59' )
		) {
			return;
		}

		\WC_Admin_Notices::add_custom_notice(
			$name,
			'<p>' . $message . '<a class="button button-primary" style="margin-left: 10px; background: ' . esc_attr( $button_color ) . '; border-color: ' . esc_attr( $button_color ) . ';" target="_blank" href="' . esc_url( $button_url ) . '">' .
				esc_html( $button_label ) .
				'</a></p>'
		);

		update_option( $name . '_added', 1 );
	}

	protected function handle_offers() {
		$this->add_offer_notice(
			'black_friday',
			date( 'Y' ) . '-11-20',
			date( 'Y' ) . '-11-30',
			'<strong>Black Friday Exclusive:</strong> SAVE up to 75% & get access to <strong>Discount Rules and Dynamic Pricing Pro</strong> features.',
			'Grab The Offer',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=black-friday&utm_medium=link',
			'#5614d5'
		);

		$this->add_offer_notice(
			'cyber_monday',
			date( 'Y' ) . '-12-01',
			date( 'Y' ) . '-12-10',
			'<strong>Cyber Monday Exclusive:</strong> Save up to 75% on <strong>Discount Rules and Dynamic Pricing Pro</strong>. Limited Time Only!',
			'Claim Your Deal',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=cyber-monday&utm_medium=link',
			'#00aaff'
		);

		$this->add_offer_notice(
			'holiday_discount',
			date( 'Y' ) . '-12-11',
			date( 'Y' ) . '-12-31',
			'<strong>Holiday Cheer:</strong> Get up to 75% OFF <strong>Discount Rules and Dynamic Pricing Pro</strong> this festive season.',
			'Shop Now',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=holiday-discount&utm_medium=link',
			'#28a745'
		);

		$this->add_offer_notice(
			'new_year_sale',
			date( 'Y' ) . '-01-01',
			date( 'Y' ) . '-01-10',
			'<strong>New Year Sale:</strong> Kickstart your projects with up to 75% OFF <strong>Discount Rules and Dynamic Pricing Pro</strong>!',
			'Get The Deal',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=new-year-sale&utm_medium=link',
			'#ff5733'
		);

		$this->add_offer_notice(
			'spring_sale',
			date( 'Y' ) . '-03-20',
			date( 'Y' ) . '-03-30',
			'<strong>Spring Into Savings:</strong> Get up to 75% OFF <strong>Discount Rules and Dynamic Pricing Pro</strong>. Refresh your store this season!',
			'Spring Deals',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=spring-sale&utm_medium=link',
			'#5cb85c'
		);

		$this->add_offer_notice(
			'summer_sale',
			date( 'Y' ) . '-06-15',
			date( 'Y' ) . '-06-25',
			'<strong>Sizzling Summer Sale:</strong> Save up to 75% on <strong>Discount Rules and Dynamic Pricing Pro</strong>. Limited time only!',
			'Cool Deals',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=summer-sale&utm_medium=link',
			'#ff5733'
		);

		$this->add_offer_notice(
			'halloween_sale',
			date( 'Y' ) . '-10-25',
			date( 'Y' ) . '-10-31',
			'<strong>Halloween Spooktacular:</strong> Scare away high prices! Get up to 75% OFF <strong>Discount Rules and Dynamic Pricing Pro</strong>. No tricks, just treats!',
			'Shop Spooky Deals',
			'https://www.asanaplugins.com/product/woocommerce-dynamic-pricing-and-discounts-plugin/?utm_source=easy-woocommerce-discounts-free&utm_campaign=halloween-sale&utm_medium=link',
			'#ff4500'
		);
	}

}
