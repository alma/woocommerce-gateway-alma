/**
 * Inject payment plan widget.
 *
 * @package Alma_WooCommerce_Gateway
 */

( function () {
	var paymentPlanContainerId = "#alma-payment-plan";
	var $paymentPlanContainer  = jQuery( paymentPlanContainerId );
	var jqueryUpdateEvent      = $paymentPlanContainer.data( "jquery-update-event" );
	var firstRender            = $paymentPlanContainer.data( "first-render" );

	window.AlmaInitWidget = function () {
		$paymentPlanContainer   = jQuery( paymentPlanContainerId ); // re-query element to get updated data.
		var enabledPlans        = $paymentPlanContainer.data( "enabled-plans" );
		var merchantId          = $paymentPlanContainer.data( "merchant-id" );
		var apiMode             = $paymentPlanContainer.data( "api-mode" );
		var amount              = parseFloat( $paymentPlanContainer.data( "amount" ) );
		var minAmount           = parseFloat( $paymentPlanContainer.data( "min-amount" ) );
		var maxAmount           = parseFloat( $paymentPlanContainer.data( "max-amount" ) );
		var amountQuerySelector = $paymentPlanContainer.data( "amount-query-selector" );

		if ( amountQuerySelector ) {
			var amountElement = document.querySelector( amountQuerySelector );
			if ( amountElement ) {
				var child = amountElement.firstChild;
				while ( child ) {
					if ( child.nodeType === Node.TEXT_NODE ) {
						amount = parseFloat( child.data.replace( ",", "." ) ) * 100;
						break;
					}
					child = child.nextSibling;
				}
			}
		}

		var eligibleInstallments = enabledPlans
			.filter(
				function ( plan ) {
					return amount >= plan.min_amount && amount <= plan.max_amount;
				}
			)
			.map(
				function ( plan ) {
					return plan.installments;
				}
			);

		var almaWidgets = Alma.Widgets.initialize( merchantId, apiMode );

		almaWidgets.create(
			Alma.Widgets.PaymentPlan,
			{
				container: paymentPlanContainerId,
				purchaseAmount: amount,
				installmentsCount: eligibleInstallments,
				minPurchaseAmount: minAmount,
				maxPurchaseAmount: maxAmount,
				templates: {
					notEligible: function ( min, max, installmentsCounts, config, createWidget ) {
						return "<b>Le paiement en plusieurs fois est disponible entre " + Alma.Utils.priceFromCents( min ) + "€ et " + Alma.Utils.priceFromCents( max ) + "€</b>";
					},
				},
			}
		);

		almaWidgets.render();
	};

	if ( firstRender ) {
		window.AlmaInitWidget();
	}

	if ( jqueryUpdateEvent ) {
		jQuery( document.body ).on(
			jqueryUpdateEvent,
			function () {
				window.AlmaInitWidget();
			}
		);
	}
} )();
