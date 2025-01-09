import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getPaymentMethodData } from '@woocommerce/settings';

const pluginCode = 'wayforpay';

const settings = getPaymentMethodData( pluginCode, {} );

const label = decodeEntities( settings.title );
const logoUrl = settings.logo_url;
/**
 * Content component
 */
const Content = () => {
	return decodeEntities( settings.description || '' );
};

/**
 * LabelWithLogo component
 *
 * @param {*} props Props from payment API.
 */
const LabelWithLogo = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	const labelContent = <PaymentMethodLabel text={ label + ' ' } />;

	// Return a flex container with the label on the left and the logo on the right
	return (
		<div>
			{ labelContent }
			{ logoUrl && (
				<img
					src={ logoUrl }
					alt={ `${ label } logo` }
					id={ 'checkout_wayforpay_logo' }
				/>
			) }
		</div>
	);
};

const WayforpayGateway = {
	name: pluginCode,
	label: <LabelWithLogo />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};

registerPaymentMethod( WayforpayGateway );
