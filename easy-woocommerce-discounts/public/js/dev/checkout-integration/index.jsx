/**
 * External dependencies
 */
import { registerCheckoutFilters } from '@woocommerce/blocks-checkout';

const isNotRemovable = ( args ) =>
	args &&
	'undefined' !== typeof args.not_removable &&
	1 == args.not_removable;

const isAutoAdded = ( args ) => args && 'undefined' !== typeof args.auto_added;

const showGiftEmoji = ( args, extensions ) => {
	if ( 0 != args?.cartItem?.totals?.line_subtotal ) {
		return false;
	}

	const data = extensions?.asnp_ewd?.data;
	if ( ! data ) {
		return false;
	}

	return data?.add_free_gift_badge;
};

const modifyCartItemClass = ( defaultValue, extensions, args ) => {
	const data = extensions?.asnp_ewd?.data;
	if ( ! data ) {
		return defaultValue;
	}

	if ( isAutoAdded( data ) ) {
		return `${ defaultValue } asnp-ewd-auto-added-item`;
	}

	return defaultValue;
};

const modifyShowRemoveItemLink = ( defaultValue, extensions, args ) => {
	const data = extensions?.asnp_ewd?.data;
	if ( ! data ) {
		return defaultValue;
	}

	return isNotRemovable( data ) ? false : defaultValue;
};

const modifyItemName = ( defaultValue, extensions, args ) => {
	if ( showGiftEmoji( args, extensions ) ) {
		return `${ defaultValue } <img draggable="false" role="img" class="emoji" alt="🎁" style="float:none; display: inline; border: none; box-shadow: none; height: 1em; width: 1em; background: none; padding: 0; vertical-align: -0.1em; margin: 0 0.07em;" src="https://s.w.org/images/core/emoji/14.0.0/svg/1f381.svg"/>`;
	}

	return defaultValue;
};

registerCheckoutFilters( 'asnp-ewd', {
	cartItemClass: modifyCartItemClass,
	showRemoveItemLink: modifyShowRemoveItemLink,
	itemName: modifyItemName,
} );
