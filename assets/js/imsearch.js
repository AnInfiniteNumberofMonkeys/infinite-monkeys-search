/**
 * Infinite Monkeys Search - front end.
 *
 * Search bars ([data-imsearch]) send queries to the fast endpoint and
 * render the JSON into their connected results ([data-imsearch-results]).
 * All result markup is built with DOM methods and text nodes, never by
 * inserting HTML strings from the server.
 */
(function ($) {
	'use strict';

	var cfg = window.imsearchConfig || {};
	var cache = {};
	var useRest = !cfg.endpoint;
	var uid = 0;
	var overlay = null;
	var inBuilder = /[?&]bricks=run\b/.test(window.location.search);

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	function nextId(prefix) {
		uid += 1;
		return prefix + '-' + uid;
	}

	function parseJSON(value, fallback) {
		if (value && typeof value === 'object') {
			return value;
		}
		try {
			return JSON.parse(value) || fallback;
		} catch (e) {
			return fallback;
		}
	}

	function fold(str) {
		var s = String(str || '');
		if (s.normalize) {
			s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
		}
		return s.toLowerCase();
	}

	function safeUrl(url) {
		return /^(https?:)?\/\//i.test(String(url || '')) || /^\//.test(String(url || '')) ? String(url) : '#';
	}

	var WORD_RE;
	try {
		WORD_RE = new RegExp('[\\p{L}\\p{N}]+', 'gu');
	} catch (e) {
		WORD_RE = /[A-Za-z0-9À-ɏ]+/g;
	}

	/**
	 * Append text to a node, wrapping the parts of words that start with a
	 * query word in <strong class="imsearch-highlight">.
	 */
	function appendHighlighted(node, text, words) {
		text = String(text || '');
		if (!words || !words.length) {
			node.appendChild(document.createTextNode(text));
			return;
		}
		var last = 0;
		var m;
		WORD_RE.lastIndex = 0;
		while ((m = WORD_RE.exec(text)) !== null) {
			var word = m[0];
			var folded = fold(word);
			var len = 0;
			for (var i = 0; i < words.length; i++) {
				if (words[i] && folded.indexOf(words[i]) === 0 && words[i].length > len) {
					len = words[i].length;
				}
			}
			if (!len) {
				continue;
			}
			if (folded.length !== word.length) {
				len = word.length; // Accent folding changed length: bold the whole word.
			}
			if (m.index > last) {
				node.appendChild(document.createTextNode(text.slice(last, m.index)));
			}
			var strong = document.createElement('strong');
			strong.className = 'imsearch-highlight';
			strong.textContent = word.slice(0, len);
			node.appendChild(strong);
			last = m.index + len;
		}
		if (last < text.length) {
			node.appendChild(document.createTextNode(text.slice(last)));
		}
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	/* ------------------------------------------------------------------ */
	/* Prices (base currency; a currency converter can re-render them)     */
	/* ------------------------------------------------------------------ */

	function formatNumber(value, c) {
		var fixed = Math.abs(value).toFixed(c.dec);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.ts);
		return (value < 0 ? '-' : '') + (parts[1] ? parts[0] + c.ds + parts[1] : parts[0]);
	}

	function amount(value) {
		var c = cfg.currency || { code: '', symbol: '$', pos: 'left', dec: 2, ts: ',', ds: '.' };
		var wrap = el('span', 'woocommerce-Price-amount amount');
		wrap.setAttribute('data-imcc-base', String(value));
		wrap.setAttribute('data-imcc-cur', c.code);
		wrap.setAttribute('data-imcc-round', '1');
		var bdi = document.createElement('bdi');
		var sym = el('span', 'woocommerce-Price-currencySymbol', c.symbol);
		var num = formatNumber(value, c);
		switch (c.pos) {
			case 'right':
				bdi.appendChild(document.createTextNode(num));
				bdi.appendChild(sym);
				break;
			case 'right_space':
				bdi.appendChild(document.createTextNode(num + ' '));
				bdi.appendChild(sym);
				break;
			case 'left_space':
				bdi.appendChild(sym);
				bdi.appendChild(document.createTextNode(' ' + num));
				break;
			default:
				bdi.appendChild(sym);
				bdi.appendChild(document.createTextNode(num));
		}
		wrap.appendChild(bdi);
		return wrap;
	}

	function priceNode(p) {
		var box = el('span', 'imsearch-result-price');
		if (p.m !== null && p.m !== undefined) {
			box.appendChild(amount(p.p));
			box.appendChild(document.createTextNode(' – '));
			box.appendChild(amount(p.m));
		} else if (p.s !== null && p.s !== undefined && p.r !== null && p.r !== undefined && p.s < p.r) {
			var del = document.createElement('del');
			del.setAttribute('aria-hidden', 'true');
			del.appendChild(amount(p.r));
			var ins = document.createElement('ins');
			ins.appendChild(amount(p.s));
			box.appendChild(del);
			box.appendChild(ins);
		} else {
			box.appendChild(amount(p.p));
		}
		return box;
	}

	/* ------------------------------------------------------------------ */
	/* Overlay                                                              */
	/* ------------------------------------------------------------------ */

	var LIFT_Z = 99991;

	function stackingAncestors(node) {
		var out = [];
		var cur = node;
		while (cur && cur !== document.body && cur.nodeType === 1) {
			var cs = window.getComputedStyle(cur);
			if (cs.position !== 'static' && cs.zIndex !== 'auto') {
				out.push(cur);
			}
			cur = cur.parentElement;
		}
		return out;
	}

	function lift(nodes) {
		var lifted = [];
		$.each(nodes, function (i, node) {
			if (!node) {
				return;
			}
			var targets = [node].concat(stackingAncestors(node.parentElement));
			$.each(targets, function (j, t) {
				if (lifted.indexOf(t) !== -1) {
					return;
				}
				lifted.push(t);
				t.setAttribute('data-imsearch-z', t.style.zIndex || '');
				t.setAttribute('data-imsearch-pos', t.style.position || '');
				if (window.getComputedStyle(t).position === 'static') {
					t.style.position = 'relative';
				}
				t.style.zIndex = String(LIFT_Z);
			});
		});
		return lifted;
	}

	function unlift(lifted) {
		$.each(lifted || [], function (i, t) {
			t.style.zIndex = t.getAttribute('data-imsearch-z') || '';
			t.style.position = t.getAttribute('data-imsearch-pos') || '';
			t.removeAttribute('data-imsearch-z');
			t.removeAttribute('data-imsearch-pos');
		});
	}

	function getOverlay() {
		if (!overlay) {
			overlay = el('div', 'imsearch-overlay');
			overlay.setAttribute('aria-hidden', 'true');
			document.body.appendChild(overlay);
			$(overlay).on('click', function () {
				$.each(Bar.all, function (i, bar) {
					bar.close();
				});
			});
		}
		return overlay;
	}

	/* ------------------------------------------------------------------ */
	/* Results                                                              */
	/* ------------------------------------------------------------------ */

	function Results(node) {
		this.node = node;
		this.$el = $(node);
		this.opts = $.extend({ types: ['product'], with: ['image', 'price'], headings: true, button: '', orderby: 'relevance', order: 'desc', for: '' }, parseJSON(node.getAttribute('data-imsearch-results'), {}));
		this.list = node.querySelector('.imsearch-results-inner') || node;
		if (!this.list.id) {
			this.list.id = nextId('imsearch-list');
		}
		this.bar = null;
		$.data(node, 'imsearchResults', this);
	}

	Results.prototype.has = function (flag) {
		return this.opts.with.indexOf(flag) !== -1;
	};

	Results.prototype.clear = function () {
		this.list.textContent = '';
		this.hide();
	};

	Results.prototype.show = function () {
		this.$el.removeClass('imsearch-results--idle').addClass('is-open');
	};

	Results.prototype.hide = function () {
		this.$el.addClass('imsearch-results--idle').removeClass('is-open');
	};

	Results.prototype.render = function (data, bar) {
		var self = this;
		var words = $.map(data.words || [], function (w) {
			return fold(w);
		});
		var groups = {};
		$.each(data.groups || [], function (i, g) {
			groups[g.type] = g;
		});

		var frag = document.createDocumentFragment();
		var count = 0;

		$.each(this.opts.types, function (i, type) {
			var g = groups[type];
			if (!g || !g.items || !g.items.length) {
				return;
			}
			var slug = String(type).replace(/[^a-z0-9_-]/gi, '-');
			var group = el('div', 'imsearch-group imsearch-group--' + slug);
			group.setAttribute('role', 'group');
			group.setAttribute('aria-label', g.label);
			if (self.opts.headings !== false) {
				group.appendChild(el('div', 'imsearch-group-heading', g.label));
			}

			$.each(g.items, function (j, item) {
				count += 1;
				var a = el('a', 'imsearch-result imsearch-result--' + slug);
				a.href = safeUrl(item.url);
				a.id = nextId('imsearch-option');
				a.setAttribute('role', 'option');
				a.setAttribute('aria-selected', 'false');

				if (self.has('image')) {
					var imgWrap = el('span', 'imsearch-result-image');
					if (item.image) {
						var img = document.createElement('img');
						img.src = safeUrl(item.image);
						img.alt = '';
						img.loading = 'lazy';
						img.decoding = 'async';
						imgWrap.appendChild(img);
					}
					a.appendChild(imgWrap);
				}

				var content = el('div', 'imsearch-result-content');
				var body = el('span', 'imsearch-result-body');
				var row = el('span', 'imsearch-result-title-row');
				var title = el('span', 'imsearch-result-title');
				appendHighlighted(title, item.title, words);
				row.appendChild(title);
				if (self.has('sku') && item.sku) {
					var sku = el('span', 'imsearch-result-sku');
					sku.appendChild(document.createTextNode('(' + ((cfg.i18n && cfg.i18n.sku) || 'SKU') + ': '));
					appendHighlighted(sku, item.sku, words);
					sku.appendChild(document.createTextNode(')'));
					row.appendChild(sku);
				}
				body.appendChild(row);
				if (self.has('excerpt') && item.excerpt) {
					var ex = el('span', 'imsearch-result-excerpt');
					appendHighlighted(ex, item.excerpt, words);
					body.appendChild(ex);
				}
				content.appendChild(body);

				var hasPrice = self.has('price') && item.price;
				if (hasPrice || self.opts.button) {
					var aside = el('span', 'imsearch-result-aside');
					if (hasPrice) {
						aside.appendChild(priceNode(item.price));
					}
					if (self.opts.button) {
						var btn = el('span', 'imsearch-result-button', self.opts.button);
						btn.setAttribute('aria-hidden', 'true');
						aside.appendChild(btn);
					}
					content.appendChild(aside);
				}
				a.appendChild(content);
				group.appendChild(a);
			});

			if (bar && type === bar.opts.moreType && g.total > g.items.length) {
				var more = el('a', 'imsearch-more', moreLabel(g.total));
				more.href = bar.searchUrl(data.query, type);
				more.id = nextId('imsearch-option');
				more.setAttribute('role', 'option');
				group.appendChild(more);
			}
			frag.appendChild(group);
		});

		if (!count) {
			frag.appendChild(el('div', 'imsearch-no-results', cfg.noResults || 'No results found'));
		}

		this.list.textContent = '';
		this.list.appendChild(frag);
		this.list.scrollTop = 0;
		this.show();
		return count;
	};

	function moreLabel(total) {
		var label = cfg.more || 'See all results';
		return label.indexOf('{count}') !== -1 ? label.replace('{count}', total) : label + ' (' + total + ')';
	}

	/* ------------------------------------------------------------------ */
	/* Search bar                                                           */
	/* ------------------------------------------------------------------ */

	function Bar(node) {
		this.node = node;
		this.$el = $(node);
		this.opts = $.extend({ mode: 'autocomplete', fields: ['title'], overlay: false, moreType: 'product' }, parseJSON(node.getAttribute('data-imsearch'), {}));
		this.$form = this.$el.find('.imsearch-form');
		this.$input = this.$el.find('.imsearch-input');
		this.$clear = this.$el.find('.imsearch-clear');
		this.results = [];
		this.timer = null;
		this.xhr = null;
		this.seq = 0;
		this.lastData = null;
		this.lastKey = '';
		this.isOpen = false;
		this.lifted = null;
		this.active = -1;
		$.data(node, 'imsearchBar', this);
		Bar.all.push(this);
		this.bind();
	}

	Bar.all = [];

	Bar.prototype.ids = function () {
		return [this.node.id, this.node.getAttribute('data-imsearch-id')];
	};

	Bar.prototype.primary = function () {
		return this.results[0];
	};

	Bar.prototype.attach = function (results) {
		results.bar = this;
		this.results.push(results);
		if (this.results.length === 1) {
			this.$input.attr('aria-controls', results.list.id);
		}
	};

	Bar.prototype.searchUrl = function (query, type) {
		var params = { s: query };
		if (type && type.indexOf('tax:') !== 0) {
			params.post_type = type;
		}
		params.ims = this.opts.fields.join(',');
		return (cfg.searchUrl || '/') + (String(cfg.searchUrl || '/').indexOf('?') === -1 ? '?' : '&') + $.param(params);
	};

	Bar.prototype.bind = function () {
		var self = this;

		this.$input.on('input', function () {
			self.toggleClear();
			if (self.opts.mode === 'autocomplete') {
				clearTimeout(self.timer);
				self.timer = setTimeout(function () {
					self.search();
				}, 80);
			} else if (!self.value()) {
				self.close(true);
			}
		});

		this.$input.on('focus', function () {
			if (self.lastData && self.value() && self.lastData.query === self.value().slice(0, 100)) {
				self.open();
			}
		});

		this.$input.on('keydown', function (e) {
			self.onKey(e);
		});

		this.$clear.on('click', function (e) {
			e.preventDefault();
			self.$input.val('');
			self.toggleClear();
			self.abort();
			self.close(true);
			self.$input.trigger('focus');
		});

		this.$form.on('submit', function (e) {
			var q = self.value();
			if (self.opts.mode === 'submit') {
				e.preventDefault();
				self.search(true);
				return;
			}
			if (q.length < (cfg.minChars || 1)) {
				e.preventDefault();
			}
		});

		this.toggleClear();
	};

	Bar.prototype.value = function () {
		return $.trim(this.$input.val() || '');
	};

	Bar.prototype.toggleClear = function () {
		this.$clear.prop('hidden', !this.$input.val());
	};

	Bar.prototype.abort = function () {
		clearTimeout(this.timer);
		if (this.xhr) {
			this.xhr.abort();
			this.xhr = null;
		}
		this.$el.removeClass('is-loading');
	};

	Bar.prototype.params = function (q) {
		var r = this.primary();
		var types = [];
		var withFlags = [];
		$.each(this.results, function (i, res) {
			$.each(res.opts.types, function (j, t) {
				if (types.indexOf(t) === -1) {
					types.push(t);
				}
			});
			$.each(res.opts.with, function (j, w) {
				if (withFlags.indexOf(w) === -1) {
					withFlags.push(w);
				}
			});
		});
		return {
			s: q,
			types: types.join(','),
			fields: this.opts.fields.join(','),
			limit: cfg.limit || 10,
			orderby: r ? r.opts.orderby : 'relevance',
			order: r ? r.opts.order : 'desc',
			'with': withFlags.join(','),
			v: cfg.v || 1
		};
	};

	Bar.prototype.search = function (force) {
		var self = this;
		var q = this.value().slice(0, 100);
		if (!this.results.length) {
			return;
		}
		if ((fold(q).match(WORD_RE) || []).join('').length < (cfg.minChars || 2)) {
			this.abort();
			this.close(true);
			return;
		}
		var params = this.params(q);
		var key = $.param(params);
		if (!force && key === this.lastKey && this.lastData) {
			this.show(this.lastData);
			return;
		}
		this.lastKey = key;
		if (cache[key]) {
			this.abort();
			this.show(cache[key]);
			return;
		}

		this.abort();
		var seq = ++this.seq;
		this.$el.addClass('is-loading');
		this.xhr = $.ajax({
			url: useRest ? cfg.rest : cfg.endpoint,
			data: params,
			dataType: 'json',
			timeout: 15000
		})
			.done(function (data) {
				if (seq !== self.seq || !data || !data.groups) {
					return;
				}
				cache[key] = data;
				self.show(data);
			})
			.fail(function (xhr, status) {
				if (status === 'abort' || seq !== self.seq) {
					return;
				}
				if (!useRest && cfg.rest) {
					useRest = true; // Fast endpoint unavailable: fall back to the REST API.
					self.lastKey = '';
					self.search(true);
				}
			})
			.always(function () {
				if (seq === self.seq) {
					self.$el.removeClass('is-loading');
					self.xhr = null;
				}
			});
	};

	Bar.prototype.show = function (data) {
		var self = this;
		this.lastData = data;
		this.active = -1;
		$.each(this.results, function (i, r) {
			r.render(data, self);
		});
		this.open();
	};

	Bar.prototype.open = function () {
		$.each(this.results, function (i, r) {
			r.show();
		});
		this.$input.attr('aria-expanded', 'true');
		this.$el.addClass('is-open');
		if (!this.isOpen && this.opts.overlay) {
			var o = getOverlay();
			var cs = window.getComputedStyle(this.node);
			var color = $.trim(cs.getPropertyValue('--imsearch-overlay-color'));
			var blur = $.trim(cs.getPropertyValue('--imsearch-overlay-blur'));
			o.style.backgroundColor = color || '';
			var filter = blur && parseFloat(blur) > 0 ? 'blur(' + blur + ')' : '';
			o.style.backdropFilter = filter;
			o.style.webkitBackdropFilter = filter;
			this.lifted = lift([this.node].concat($.map(this.results, function (r) {
				return r.node;
			})));
			o.classList.add('is-visible');
		}
		this.isOpen = true;
	};

	Bar.prototype.close = function (clear) {
		this.setActive(-1);
		$.each(this.results, function (i, r) {
			if (clear) {
				r.clear();
			} else {
				r.hide();
			}
		});
		if (clear) {
			this.lastData = null;
			this.lastKey = '';
		}
		this.$input.attr('aria-expanded', 'false');
		this.$el.removeClass('is-open');
		if (this.isOpen && this.opts.overlay && overlay) {
			overlay.classList.remove('is-visible');
			unlift(this.lifted);
			this.lifted = null;
		}
		this.isOpen = false;
	};

	Bar.prototype.options = function () {
		var r = this.primary();
		return r ? r.$el.find('.imsearch-result, .imsearch-more').toArray() : [];
	};

	Bar.prototype.setActive = function (index) {
		var opts = this.options();
		$(opts).removeClass('is-active').attr('aria-selected', 'false');
		this.active = index;
		if (index >= 0 && opts[index]) {
			var node = opts[index];
			$(node).addClass('is-active').attr('aria-selected', 'true');
			this.$input.attr('aria-activedescendant', node.id);
			if (node.scrollIntoView) {
				node.scrollIntoView({ block: 'nearest' });
			}
		} else {
			this.$input.removeAttr('aria-activedescendant');
		}
	};

	Bar.prototype.onKey = function (e) {
		var opts = this.options();
		switch (e.key) {
			case 'ArrowDown':
				if (this.isOpen && opts.length) {
					e.preventDefault();
					this.setActive(this.active + 1 >= opts.length ? 0 : this.active + 1);
				}
				break;
			case 'ArrowUp':
				if (this.isOpen && opts.length) {
					e.preventDefault();
					this.setActive(this.active - 1 < 0 ? opts.length - 1 : this.active - 1);
				}
				break;
			case 'Enter':
				if (this.isOpen && this.active >= 0 && opts[this.active]) {
					e.preventDefault();
					window.location.href = opts[this.active].href;
				}
				break;
			case 'Escape':
				if (this.isOpen) {
					e.preventDefault();
					e.stopPropagation(); // First Escape closes the results, not a surrounding offcanvas.
					this.close(false);
				} else if (this.$input.val()) {
					e.preventDefault();
					this.$input.val('');
					this.toggleClear();
					this.close(true);
				}
				break;
		}
	};

	/* ------------------------------------------------------------------ */
	/* Init & pairing                                                       */
	/* ------------------------------------------------------------------ */

	function makeDropdown(bar) {
		var node = el('div', 'imsearch-results imsearch-results--dropdown imsearch-results--idle');
		node.setAttribute('data-imsearch-results', JSON.stringify({ types: ['product'], with: ['image', 'price'] }));
		var inner = el('div', 'imsearch-results-inner');
		inner.setAttribute('role', 'listbox');
		node.appendChild(inner);
		bar.node.appendChild(node);
		return new Results(node);
	}

	function initAll(context) {
		if (inBuilder) {
			return;
		}
		var root = context || document;
		$(root).find('.imsearch-bar[data-imsearch]').each(function () {
			if (!$.data(this, 'imsearchBar')) {
				new Bar(this); // eslint-disable-line no-new
			}
		});

		var bars = Bar.all;
		$(root).find('.imsearch-results[data-imsearch-results]').each(function () {
			if ($.data(this, 'imsearchResults') || $(this).hasClass('imsearch-results--sample')) {
				return;
			}
			var results = new Results(this);
			var target = null;
			var wanted = String(results.opts['for'] || this.getAttribute('data-for') || '').replace(/^#/, '');
			if (wanted) {
				$.each(bars, function (i, b) {
					if (b.ids().indexOf(wanted) !== -1) {
						target = b;
						return false;
					}
				});
			}
			if (!target) {
				// Nearest bar before this element, else the first bar.
				var node = this;
				$.each(bars, function (i, b) {
					if (b.node.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING) {
						target = b;
					}
				});
				target = target || bars[0] || null;
			}
			if (target) {
				target.attach(results);
			}
		});

		$.each(bars, function (i, b) {
			if (!b.results.length) {
				b.$el.css('position', 'relative');
				b.attach(makeDropdown(b));
			}
		});
	}

	// Close when clicking outside a bar and its results.
	$(document).on('mousedown touchstart', function (e) {
		$.each(Bar.all, function (i, bar) {
			if (!bar.isOpen) {
				return;
			}
			var inside = $.contains(bar.node, e.target) || bar.node === e.target;
			$.each(bar.results, function (j, r) {
				if ($.contains(r.node, e.target) || r.node === e.target) {
					inside = true;
				}
			});
			if (!inside) {
				bar.close(false);
			}
		});
	});

	window.imsearchInit = initAll;
	window.imsearchBricksInit = function () {
		// Inside the builder the elements show static previews.
	};

	$(function () {
		initAll(document);
	});
})(jQuery);
