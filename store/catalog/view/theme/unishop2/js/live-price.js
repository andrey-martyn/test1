function uniLivePrice() {
	var ChangePrice = function(el) {
		var el = $(el), elem;
		
		elem = el.closest('.product-thumb');
		
		if(!elem.length) {
			elem = el.closest('.product-block');
		}
	
		if(elem.length) {
			var quantity = elem.find('.qty-switch__input').val(), 
				option_price = 0;
				
				if(typeof(quantity) == 'undefined' || quantity <= 0) quantity = 1;
				
			var elem2 = elem.find('.price'),
				price = parseFloat(elem2.data('price')),
				price2 = parseFloat(elem2.data('old-price')),
				special = parseFloat(elem2.data('special')),
				special2 = parseFloat(elem2.data('old-special'));


			var old_price = price2 ? price2 : price,
				old_special = special2 ? special2 : special,
				price_elem = elem2.find('.price-old'),
				special_elem = elem2.find('.price-new');

			var discounts = elem2.data('discount');

            var eurcurr = parseFloat(elem2.data('eurcurr')),
                eurprice_elem = elem.find('.eur-price');

            var old_eurprice = (old_price / eurcurr).toFixed(1);
	
			if(discounts && special <= 0) {
				discounts = JSON.parse(discounts.replace(/'/g, '"'));
	
				for (var i in discounts) {
					var d_quantity = parseFloat(discounts[i]['quantity']);
					var d_price = parseFloat(discounts[i]['price']);
		
					if((quantity >= d_quantity) && (d_price < price)) {
						price = d_price;
					}
				}
			}
	
			elem.find('input:checked, option:selected').each(function() {
				if ($(this).data('prefix') == '+') {
					option_price += parseFloat($(this).data('price'));
				}
				if ($(this).data('prefix') == '-') {
					option_price -= parseFloat($(this).data('price'));
				}
				if ($(this).data('prefix') == '*') {
					price *= parseFloat($(this).data('price'));
					special *= parseFloat($(this).data('price'));
				}
				if ($(this).data('prefix') == '/') {
					price /= parseFloat($(this).data('price'));
					special /= parseFloat($(this).data('price'));
				}
				if ($(this).data('prefix') == '=') {
					option_price += parseFloat($(this).data('price'));
					
					if(parseFloat($(this).data('price')) > 0) {
						price = 0;
						special = 0;
					}
				}
			});
	
			var new_price = (price + option_price) * quantity;
			var new_special = (special + option_price) * quantity;

			if(special) {
				AnimatePrice(old_price, new_price, price_elem);
				AnimatePrice(old_special, new_special, special_elem);
			} else {
				AnimatePrice(old_price, new_price, elem2);
			}

			elem2.data('old-price', new_price);
			elem2.data('old-special', new_special);

            var new_eurprice = ((price + option_price) / eurcurr * quantity).toFixed(1);

            if(eurcurr) {
				AnimatePriceEur(old_eurprice, new_eurprice, eurprice_elem);
             }



		}
	}
	
	var AnimatePrice = function(old_price, new_price, elem){
        var previous = elem.data('uniPriceAnimation');
        if (previous) previous.stop(true, false);
        if (elem.closest('.lavka-mobile-card').length && window.matchMedia('(max-width: 519px)').matches) {
            if (isFinite(new_price)) elem.text(PriceFormat(new_price));
            return;
        }
		if(new_price != old_price) {
            var animation = $({val:old_price});
            elem.data('uniPriceAnimation', animation);
			animation.animate({val:new_price}, {
				duration:300,
				step: function(val) {
					elem.text(PriceFormat(val));
				}
			});
		}
	}

	var AnimatePriceEur = function(old_price, new_price, elem){
		if(new_price != old_price) {
			$({val:old_price}).animate({val:new_price}, {
				duration:300,
				step: function(val) {
					elem.text(PriceFormatEur(val));
				}
			});
		}
	}

	var PriceFormat = function(n) {
		var c, d, t, s_left, s_right, i, j;
		c = uniJsVars.currency.decimal != 0 ? uniJsVars.currency.decimal : '';
		d = uniJsVars.currency.decimal_p;
		t = uniJsVars.currency.thousand_p;
		s_left = uniJsVars.currency.symbol_l;
		s_right = uniJsVars.currency.symbol_r;
		i = parseInt(n = Math.abs(n).toFixed(c)) + '';
		j = ((j = i.length) > 3) ? j % 3 : 0;

		return s_left + (j ? i.substr(0, j) + t : '') + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : '') + s_right;
	}

	var PriceFormatEur = function(n) {
		var c, d, t, s_right, i, j;
		c = 1;
		d = uniJsVars.currency.decimal_p;
		t = uniJsVars.currency.thousand_p;
		s_right = ' €';
		i = parseInt(n = Math.abs(n).toFixed(c)) + '';
		j = ((j = i.length) > 3) ? j % 3 : 0;

		return (j ? i.substr(0, j) + t : '') + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : '') + s_right;
	}

	
	$(document).off('change.uniLivePrice').on('change.uniLivePrice', '.qty-switch__input, .option input[type="checkbox"], .option input[type="radio"], .option select', function() {
		ChangePrice(this);
	});
		
	$('.qty-switch__input').each(function() {
		if($(this).val() > 1) {
			ChangePrice(this);
		}
	});
	
	$(document).off('ajaxStop.uniLivePrice').on('ajaxStop.uniLivePrice', function() {
		$('.qty-switch__input').each(function() {
			if($(this).val() > 1) {
				ChangePrice(this); 
			}
		});
	});
};
	
$(function() {	
	uniLivePrice();
});