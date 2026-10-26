<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{  translate('INVOICE') }}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
	<style media="all">
        @page {
			margin: 0;
			padding:0;
		}
		body{
			font-size: 0.875rem;
            font-family: '<?php echo  $font_family ?>';
            font-weight: normal;
            direction: <?php echo  $direction ?>;
            text-align: <?php echo  $text_align ?>;
			padding:0;
			margin:0; 
		}
		.gry-color *,
		.gry-color{
			color:#000;
		}
		table{
			width: 100%;
		}
		table th{
			font-weight: normal;
		}
		table.padding th{
			padding: .25rem .7rem;
		}
		table.padding td{
			padding: .25rem .7rem;
		}
		table.sm-padding td{
			padding: .1rem .0rem .1rem .7rem;
		}
		.border-bottom td,
		.border-bottom th{
			border-bottom:1px solid #eceff4;
		}
		.text-left{
			text-align:<?php echo  $text_align ?>;
		}
		.text-right{
			text-align:<?php echo  $not_text_align ?>;
		}
		.font-weight{
			font-weight: 900;
		}
		.strong{
			font-weight: bold;
		}
	</style>
</head>
<body>
	<div>

		@php
			$logo = (json_decode(get_setting('business_info'), true) ?? [])['invoice_logo'] ?? null;
			$shipping = json_decode($order->shipping_address);
			$billing = json_decode($order->billing_address) ?? $shipping;
			$first_order = $order->orderDetails->first();
		@endphp


		<div style=" padding: 0 4rem;">
			<div style="padding: 2rem 0 1rem 0">
				<table>
					<tr>
						<td>
							@if($logo != null)
								<img src="{{ uploaded_asset($logo) }}" height="30" style="display:inline-block;">
							@else
								<img src="{{ static_asset('assets/img/logo.png') }}" height="30" style="display:inline-block;">
							@endif
						</td>
						<td style="font-size: 1.5rem;" class="text-right strong">@if($order->order_from=='pos'){{  translate('POS INVOICE') }}@else{{  translate('INVOICE') }}@endif</td>
					</tr>
				</table>
			</div>
		</div>

		<div  style="padding:0.5rem 4rem; padding-bottom:0">
				<div>
					<table width="100%">
						<tr>
							<!-- LEFT COLUMN -->
							<td width="50%" valign="top">
								<table>
									<tr>
										<td style="font-size: 1.12rem;" class="strong">{{ get_setting('site_name') }}</td>
									</tr>
									<tr>
										<td class="gry-color small">{{ get_setting('contact_address') }}</td>
									</tr>
									<tr>
										<td class="gry-color small">{{  translate('Email') }}: {{ get_setting('contact_email') }}</td>
									</tr>
									<tr>
										<td class="gry-color small">{{  translate('Phone') }}: {{ get_setting('contact_phone') }}</td>
									</tr>
								</table>
								<br>
								 <!-- Sold BY -->
								<table>
									<tr>
										<td style="font-size: 1.12rem;" class="strong">{{ translate('Sold By') }}:</td>
									</tr>
									<tr>
										<td class="gry-color small strong">{{ $order->shop->name ?? get_setting('site_name') }}</td>
									</tr>
									<tr>
										<td class="gry-color small">{{ get_seller_address($order) }} </td>
									</tr>
									
								</table>
								<br>
								<!-- GSTIN -->
								<table>
									<tr>
										@php 
											$gstin = get_seller_gstin($order);
										@endphp
										<td class="gry-color small">@if($gstin != null && is_numeric($first_order->gst_amount)) <span class="strong">{{ translate('GSTIN') }}:</span> {{ $gstin }} @endif</td>
									</tr>
								</table>
								<br>
								<table>
									<tr>
										<td>
											<span class="gry-color small strong">
												{{  translate('Payment method') }}:
											</span> 
											<span class="">
												{{ translate(ucfirst(str_replace('_', ' ', $order->payment_type))) }}
											</span>
										</td>
									</tr>
									<tr>
										<td class="gry-color small">
											<span class="gry-color small strong">
												{{  translate('Delivery Type') }}:
											</span> 
											<span class="">
												@if ($order->shipping_type != null && $order->shipping_type == 'home_delivery')
													{{ translate('Home Delivery') }}
												@elseif ($order->shipping_type == 'pickup_point')
													@if ($order->pickup_point != null)
														{{ $order->pickup_point->getTranslation('name') }} ({{ translate('Pickip Point') }})
													@else
														{{ translate('Pickup Point') }}
													@endif
												@elseif ($order->shipping_type == 'carrier')
													@if ($order->carrier != null)
														{{ $order->carrier->name }} ({{ translate('Carrier') }})
														<br>
														{{ translate('Transit Time').' - '.$order->carrier->transit_time }}
													@else
														{{ translate('Carrier') }}
													@endif
												@else
													{{ translate('N/A') }}

												@endif
											</span>
										</td>
									</tr>
								</table>
							</td>

							<!-- RIGHT COLUMN -->
							<td width="50%" valign="top">
								<!-- Order Detail -->
								<table>
									<tr class="">
										<td class="gry-color small text-right"><span class="strong">{{ translate('Order ID') }}:</span> {{ $order->code }}</td>
										
									</tr>
									<tr>
										<td class="gry-color small text-right"><span class="strong">{{ translate('Order Date') }}:</span> {{ date('d-m-Y', $order->date) }}</td>
									</tr>
								</table>
								<table width="100%">
									<tr><td class="strong gry-color" style="font-size: 1.12rem;">{{ translate('Bill to') }}:</td></tr>
									<tr><td class="strong">{{ $billing->name }}</td></tr>
									<tr>
										<td class="gry-color small">
											{{ $billing->address ? $billing->address.',' : '' }}
											{{ $billing->city ? $billing->city.',' : '' }}
											{{ !empty($billing->state) ? $billing->state.' - ' : '' }}
											{{ $billing->postal_code ? $billing->postal_code.',' : '' }}
											{{ $billing->country }}
										</td>
									</tr>

									@if($billing->email)
									<tr><td class="gry-color small">{{ translate('Email') }}: {{ $billing->email }}</td></tr>
									@endif
									@if($billing->phone)
									<tr><td class="gry-color small">{{ translate('Phone') }}: {{ $billing->phone }}</td></tr>
									@endif
								</table>
								<br>

								<table width="100%">
									<tr><td class="strong gry-color" style="font-size: 1.12rem;">{{ translate('Ship to') }}:</td></tr>
									<tr><td class="strong">{{ $shipping->name }}</td></tr>
									<tr>
										<td class="gry-color small">
											{{ $shipping->address ? $shipping->address.',' : '' }}
											{{ $shipping->city ? $shipping->city.',' : '' }}
											{{ !empty($shipping->state) ? $shipping->state.' - ' : '' }}
											{{ $shipping->postal_code ? $shipping->postal_code.',' : '' }}
											{{ $shipping->country }}
										</td>

									</tr>
									@if($shipping->email)
									<tr><td class="gry-color small">{{ translate('Email') }}: {{ $shipping->email }}</td></tr>
									@endif
									@if($shipping->phone)
									<tr><td class="gry-color small">{{ translate('Phone') }}: {{ $shipping->phone }}</td></tr>
									@endif
								</table>
							</td>
						</tr>
					</table>
				</div>
		</div>
			



		<div style="padding: 1rem 4rem;">
				<div>
					<table class="padding text-left small border-bottom">
						<thead>
							<tr class="gry-color " style="background-color: #eceff4">
								<th width="35%" class="text-left">{{ translate('Product Name') }}</th>
								<th width="10%" class="text-left">{{ translate('Qty') }}</th>
								
								@if(is_numeric($first_order->gst_amount))
								<th width="15%" class="text-left">{{ translate('Gross Amount')}}</th>
								<th width="15%" class="text-left">{{ translate('Discount/ Coupon')}}</th>
								<th width="15%" class="text-left">{{ translate('Taxable Value')}}</th>

								@if(same_state_shipping($order))
								<th width="10%" class="text-left">{{ translate('CGST') }}</th>
								<th width="10%" class="text-left">{{ translate('SGST') }}</th>
								@else
								<th width="10%" class="text-left">{{ translate('IGST') }}</th>
								@endif

								@else
								<th width="15%" class="text-left">{{ translate('Unit Price') }}</th>
								<th width="10%" class="text-left">{{ translate('Tax') }}</th>
								@endif
								
								<th width="15%" class="text-right">{{ translate('Total') }}</th>
							</tr>
						</thead>
						<tbody class="strong">
							@foreach ($order->orderDetails as $key => $orderDetail)
								@if ($orderDetail->product != null)
									<tr class="">
										<td>
											{{ $orderDetail->product->name }} 
											@if($orderDetail->variation != null) ({{ $orderDetail->variation }}) @endif
											<br>
											<small>
												@php
													$product_stock = json_decode($orderDetail->product->stocks->first(), true);
												@endphp
												{{translate('SKU')}}: {{ $product_stock['sku'] ?? 'N/A' }}
											</small>
										</td>
										<td class="">{{ $orderDetail->quantity }}</td>

										@if(is_numeric($first_order->gst_amount))
										<td class="border-top-0 border-bottom">
											{{ single_price($orderDetail->price) }}
										</td>

										<td class="border-top-0 border-bottom">
											{{ single_price($orderDetail->coupon_discount) }}
										</td>

										<td class="border-top-0 border-bottom">
											{{ single_price($orderDetail->price - $orderDetail->coupon_discount) }}
										</td>
										
										@php 
											$gst_amount = get_gst_by_price_and_rate($orderDetail->price - $orderDetail->coupon_discount , $orderDetail->gst_rate);
											$shipping_gst = get_gst_by_price_and_rate($orderDetail->shipping_cost, $orderDetail->gst_rate);
										@endphp

										@if(same_state_shipping($order))
										<td class="border-top-0 border-bottom">
											{{ single_price($gst_amount/2) }}
										</td>
										<td class="border-top-0 border-bottom">
											{{ single_price($gst_amount/2) }}
										</td>
										@else
										<td class="border-top-0 border-bottom">
											{{ single_price($gst_amount) }}
										</td>	
										@endif

										@else
										<td class="currency">{{ single_price($orderDetail->price/$orderDetail->quantity) }}</td>
										<td class="currency">{{ single_price($orderDetail->tax/$orderDetail->quantity) }}</td>
										@endif

										@if(is_numeric($first_order->gst_amount))
										<td class="text-right currency">{{ single_price($orderDetail->price - $orderDetail->coupon_discount + $gst_amount) }}</td>
										@else
										<td class="text-right currency">{{ single_price($orderDetail->price+$orderDetail->tax) }}</td>
										@endif
										
									</tr>
									@if(is_numeric($first_order->gst_amount))
									<tr>
										<td class="border-top-0 border-bottom">
											{{translate('Shipping')}}
										</td>
										<td class="border-top-0 border-bottom">
											1
										</td>
										<td class="border-top-0 border-bottom">
											{{ single_price($orderDetail->shipping_cost) }}
										</td>
										<td class="border-top-0 border-bottom">
											{{ single_price(0) }}
										</td>
										<td class="border-top-0 border-bottom">
											{{ single_price($orderDetail->shipping_cost) }}
										</td>
										@if(same_state_shipping($order))
										<td class="border-top-0 border-bottom">
											{{ single_price($shipping_gst/2) }}
										</td>
										<td class="border-top-0 border-bottom">
											{{ single_price($shipping_gst/2) }}
										</td>
										@else
										<td class="border-top-0 border-bottom">
											{{ single_price($shipping_gst) }}
										</td>
										@endif
										<td class="border-top-0 border-bottom pr-0 text-right">{{ single_price($orderDetail->shipping_cost + (($orderDetail->shipping_cost* $orderDetail->gst_rate)/100)) }}
										</td>
									</tr>
									@endif
								@endif
							@endforeach
						</tbody>
					</table>
				</div>
		</div>

			
		<div style="padding:0 4rem;">
			<div>
					<table class="text-right sm-padding small">
						<thead>
							<tr>
								<th width="60%"></th>
								<th width="40%"></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="text-left">
									@php
										$removedXML = '<?xml version="1.0" encoding="UTF-8"?>';
									@endphp
									{!! str_replace($removedXML,"", QrCode::size(100)->generate($order->code)) !!}
								</td>
								<td>
									<table class="text-right sm-padding small">
										<tbody>
											@if(is_numeric($first_order->gst_amount))
											<tr>
												<th class="gry-color text-left">{{ translate('Sub Total') }}</th>
												<td class="currency">{{ single_price($order->orderDetails->sum('price') + $order->orderDetails->sum('shipping_cost') - $order->orderDetails->sum('coupon_discount')) }}</td>
											</tr>
											<tr class="border-bottom">
												<th class="gry-color text-left">{{ translate('Total GST') }}</th>
												<td class="currency">{{ single_price($order->orderDetails->sum('gst_amount')) }}</td>
											</tr>
											
											@else
											<tr>
												<th class="gry-color text-left">{{ translate('Sub Total') }}</th>
												<td class="currency">{{ single_price($order->orderDetails->sum('price')) }}</td>
											</tr>
											<tr>
												<th class="gry-color text-left">{{ translate('Shipping Cost') }}</th>
												<td class="currency">{{ single_price($order->orderDetails->sum('shipping_cost')) }}</td>
											</tr>
											<tr class="border-bottom">
												<th class="gry-color text-left">{{ translate('Total Tax') }}</th>
												<td class="currency">{{ single_price($order->orderDetails->sum('tax')) }}</td>
											</tr>
											<tr class="border-bottom">
												<th class="gry-color text-left">{{ translate('Coupon Discount') }}</th>
												<td class="currency">{{ single_price($order->coupon_discount) }}</td>
											</tr>
											@endif
											<tr>
												<th class="text-left "><span style="font-weight: bold;">{{ translate('Grand Total') }}</span></th>
												<td class="currency"><span style="font-weight: bold;">{{ single_price($order->grand_total) }}</span></td>
											</tr>
										</tbody>
									</table>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

		</div>
		
	</div>
</body>
</html>
