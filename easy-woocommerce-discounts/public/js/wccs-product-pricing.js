( function ( $ ) {
	'use strict';

	/**
	 * All of the code for your public-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */

	/**
	 * ProductPricing class which handles discounted prices.
	 *
	 * @since  1.0.0
	 */
	var ProductPricing = function () {
		// Methods.
		this.init = this.init.bind( this );
		this.onFoundVariation = this.onFoundVariation.bind( this );
		this.onHideVariation = this.onHideVariation.bind( this );
		this.listeners = this.listeners.bind( this );
		this.setActiveTier = this.setActiveTier.bind( this );
		this.getProductId = this.getProductId.bind( this );
		this.tracks = this.tracks.bind( this );

		this.init();
	};

	/**
	 * Initialize.
	 */
	ProductPricing.prototype.init = function () {
		this.$bulkTables = $( '.wccs-bulk-pricing-table-container' );
		this.$bulkTitles = $( '.wccs-bulk-pricing-table-title' );
		this.$messages = $(
			'.wccs-purchase-message, .wccs-shortcode-purchase-message'
		);

		if ( $( '.variations_form' ).length ) {
			this.$parentTable = this.$bulkTables.not( '[data-variation]' );
			this.$parentTableTitle = this.$bulkTitles.not( '[data-variation]' );
			this.$parentMessage = this.$messages.not( '[data-variation]' );
			this.$variationForm = $( '.variations_form' ).first();
			$( document.body ).on(
				'found_variation.wccs_product_pricing',
				this.$variationForm,
				this.onFoundVariation
			);
			$( document.body ).on(
				'hide_variation.wccs_product_pricing',
				this.$variationForm,
				this.onHideVariation
			);
		}

		this.$cartForm = $( '.product form.cart' ).first();
		this.listeners();
		this.tracks();
	};

	/**
	 * Listeners.
	 *
	 * @since   2.2.0
	 *
	 * @returns void
	 */
	ProductPricing.prototype.listeners = function () {
		var that = this;
		this.$cartForm.on( 'change keyup', 'input, .qty', function () {
			if ( 'variation_id' === $( this ).attr( 'name' ) ) {
				return;
			}

			var productId = that.getProductId();
			var variationId = $( 'input[name="variation_id"' ).val();

			that.setActiveTier(
				variationId && 0 < variationId * 1 ? variationId : productId
			);
		} );

		// Listen to tier clicks on vertical tables
		$( document ).on( 'click', '.wccs-vertical-table tbody tr', function (
			e
		) {
			if ( $( e.target ).is( 'a, button' ) ) {
				return;
			}

			var $row = $( this );
			var $tdQty = $row.find( 'td[data-type="quantity"]' );
			if ( ! $tdQty.length ) {
				return;
			}

			var min =
				$tdQty.attr( 'data-quantity-min' ) ||
				$tdQty.data( 'quantity-min' );
			var max =
				$tdQty.attr( 'data-quantity-max' ) ||
				$tdQty.data( 'quantity-max' );
			min = min && ! isNaN( min * 1 ) ? min * 1 : 1;
			max = max && ! isNaN( max * 1 ) ? max * 1 : '';

			var targetQty = max !== '' ? max : min;

			var $qtyInput = that.$cartForm
				? that.$cartForm.find( 'input.qty' )
				: null;
			if ( $qtyInput && $qtyInput.length ) {
				$qtyInput.val( targetQty ).trigger( 'change' );
			}

			var $radio = $row.find( '.wccs-tier-radio' );
			if ( $radio.length ) {
				$radio.prop( 'checked', true );
			}
		} );

		// Listen to tier clicks on horizontal tables
		$( document ).on( 'click', '.wccs-horizontal-table td', function ( e ) {
			if ( $( e.target ).is( 'a, button' ) ) {
				return;
			}
			var $cell = $( this );
			var colIndex = $cell.index();
			var $table = $cell.closest( '.wccs-horizontal-table' );

			var $tdQty = $table.find( 'tr' ).first().children().eq( colIndex );
			if (
				! $tdQty.length ||
				$tdQty.is( 'th' ) ||
				$tdQty.attr( 'data-type' ) !== 'quantity'
			) {
				$tdQty = $table
					.find( 'td[data-type="quantity"]' )
					.filter( function () {
						return $( this ).index() === colIndex;
					} );
			}
			if ( ! $tdQty.length ) {
				return;
			}

			var min =
				$tdQty.attr( 'data-quantity-min' ) ||
				$tdQty.data( 'quantity-min' );
			var max =
				$tdQty.attr( 'data-quantity-max' ) ||
				$tdQty.data( 'quantity-max' );
			min = min && ! isNaN( min * 1 ) ? min * 1 : 1;
			max = max && ! isNaN( max * 1 ) ? max * 1 : '';

			var targetQty = max !== '' ? max : min;

			var $qtyInput = that.$cartForm
				? that.$cartForm.find( 'input.qty' )
				: null;
			if ( $qtyInput && $qtyInput.length ) {
				$qtyInput.val( targetQty ).trigger( 'change' );
			}

			var $radioRow = $table.find( 'tr.wccs-row-select' );
			if ( $radioRow.length ) {
				var $radio = $radioRow
					.children()
					.eq( colIndex )
					.find( '.wccs-tier-radio' );
				if ( $radio.length ) {
					$radio.prop( 'checked', true );
				}
			}
		} );
	};

	/**
	 * Handler function execute when WooCommerce found_variation triggered.
	 *
	 * @since  1.0.0
	 *
	 * @param  event
	 * @param  variation
	 *
	 * @return void
	 */
	ProductPricing.prototype.onFoundVariation = function ( event, variation ) {
		// Bulk pricing table.
		if ( this.$bulkTables.length ) {
			this.$bulkTables.hide();
			this.$bulkTitles.hide();
			if (
				this.$bulkTables.filter(
					'[data-variation="' + variation.variation_id + '"]'
				).length
			) {
				this.$bulkTables
					.filter(
						'[data-variation="' + variation.variation_id + '"]'
					)
					.show();
				this.$bulkTitles
					.filter(
						'[data-variation="' + variation.variation_id + '"]'
					)
					.show();
			} else if ( this.$parentTable.length ) {
				this.$parentTable.show();
				this.$parentTableTitle.show();
			}
		}

		// Purchase pricing messages.
		if ( this.$messages.length ) {
			this.$messages.hide();
			if (
				this.$messages.filter(
					'[data-variation="' + variation.variation_id + '"]'
				).length
			) {
				this.$messages
					.filter(
						'[data-variation="' + variation.variation_id + '"]'
					)
					.show();
			} else if ( this.$parentMessage.length ) {
				this.$parentMessage.show();
			}
		}
	};

	/**
	 * Handler function execute when WooCommerce hide_variation triggered.
	 *
	 * @since  1.0.0
	 *
	 * @param  event
	 *
	 * @return void
	 */
	ProductPricing.prototype.onHideVariation = function ( event ) {
		// Bulk pricing table.
		if ( this.$bulkTables.length ) {
			this.$bulkTables.hide();
			this.$bulkTitles.hide();
			if ( this.$parentTable.length ) {
				this.$parentTable.show();
				this.$parentTableTitle.show();
			}
		}

		// Purchase pricing messages.
		if ( this.$messages.length ) {
			this.$messages.hide();
			if ( this.$parentMessage.length ) {
				this.$parentMessage.show();
			}
		}
	};

	/**
	 * Set active tier on bulk pricing table container.
	 *
	 * @param   {number|string} [productId] Product or variation ID.
	 *
	 * @returns {jQuery|null} Matching quantity cell ($td) if found.
	 */
	ProductPricing.prototype.setActiveTier = function ( productId = null ) {
		if ( ! this.$bulkTables.length ) {
			return null;
		}

		var $table;
		if ( productId ) {
			$table = $(
				'.wccs-bulk-pricing-table-container[data-product="' +
					productId +
					'"], .wccs-bulk-pricing-table-container[data-variation="' +
					productId +
					'"]'
			);
		}

		if ( ! $table || ! $table.length ) {
			$table = this.$bulkTables.filter( ':visible' );
		}

		if ( ! $table.length ) {
			return null;
		}

		$table.find( 'th, td, tr' ).removeClass( 'wccs-active-tier' );
		$table.find( '.wccs-tier-radio' ).prop( 'checked', false );

		var quantity = $( '.qty', this.$cartForm ).val();
		if ( ! quantity || isNaN( quantity * 1 ) || 0 >= quantity * 1 ) {
			return null;
		}

		var $activeTd = null;

		$table.each( function () {
			var $container = $( this );
			var $td = null;

			$(
				'td[data-type="quantity"], th[data-type="quantity"]',
				$container
			).each( function () {
				var $this = $( this );
				var min = $this.data( 'quantity-min' );
				var max = $this.data( 'quantity-max' );
				min = min && ! isNaN( min * 1 ) ? min * 1 : '';
				max = max && ! isNaN( max * 1 ) && 0 < max * 1 ? max * 1 : '';

				if ( '' !== min && quantity >= min ) {
					if ( '' === max || quantity <= max ) {
						$td = $this;
						return false;
					}
				}
			} );

			if ( $td ) {
				$activeTd = $td;
				var $bulkTable = $container.find( 'table' );
				if ( $bulkTable.hasClass( 'wccs-horizontal-table' ) ) {
					var colIndex = $td.index();
					$bulkTable.find( 'tr' ).each( function () {
						$( this )
							.children()
							.eq( colIndex )
							.addClass( 'wccs-active-tier' );
					} );
					$container
						.find( '.wccs-horizontal-table tr.wccs-row-select' )
						.children()
						.eq( colIndex )
						.find( '.wccs-tier-radio' )
						.prop( 'checked', true );
				} else {
					$td.closest( 'tr' ).addClass( 'wccs-active-tier' );
					$td.closest( 'tr' )
						.find( 'th, td' )
						.addClass( 'wccs-active-tier' );
					$td.closest( 'tr' )
						.find( '.wccs-tier-radio' )
						.prop( 'checked', true );
				}
			}
		} );

		return $activeTd;
	};

	ProductPricing.prototype.getProductId = function () {
		var productId = $( 'button[name="add-to-cart"]' ).val();
		return productId ? productId : $( 'input[name="add-to-cart"' ).val();
	};

	ProductPricing.prototype.tracks = function () {
		if (
			'undefined' === typeof wccs_product_pricing_params.analytics ||
			0 >= wccs_product_pricing_params.analytics
		) {
			return;
		}

		var productId = this.getProductId();

		if (
			! productId &&
			'undefined' !== typeof wccs_product_pricing_params.product_id
		) {
			productId = wccs_product_pricing_params.product_id;
		}

		if ( ! productId || 0 >= productId * 1 ) {
			return;
		}

		$.ajax( {
			url: wccs_product_pricing_params.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'asnp_wccs_product_tracks',
				nonce: wccs_product_pricing_params.nonce,
				product_id: productId,
			},
		} );
	};

	/**
	 * Creating a singleton instance of ProductPricing.
	 */
	var Singleton = ( function () {
		var instance;

		return {
			getInstance: function () {
				if ( ! instance ) {
					instance = new ProductPricing();
				}
				return instance;
			},
		};
	} )();

	$.fn.wccs_get_product_pricing = function () {
		return Singleton.getInstance();
	};

	$( function () {
		$().wccs_get_product_pricing();
	} );

	// Porto theme skeleton compatibility.
	$( document ).on( 'skeleton-loaded', '.skeleton-loading', function () {
		var productPricing = Singleton.getInstance();
		productPricing.init();
	} );
} )( jQuery );
