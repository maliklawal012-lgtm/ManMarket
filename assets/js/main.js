(() => {
    'use strict';

    const FAV_KEY = 'manmarket_favorites';
    const CART_KEY = 'manmarket_cart';

    const readList = (key) => {
        try {
            return JSON.parse(localStorage.getItem(key)) || [];
        } catch (err) {
            return [];
        }
    };
    const writeList = (key, list) => localStorage.setItem(key, JSON.stringify(list));
    const normalize = (str) => str.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    /* ---------- Scroll-reveal (apparition au defilement) ---------- */
    const revealEls = document.querySelectorAll('.reveal');
    if (revealEls.length) {
        if ('IntersectionObserver' in window) {
            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
            revealEls.forEach((el) => revealObserver.observe(el));
        } else {
            revealEls.forEach((el) => el.classList.add('is-visible'));
        }
    }

    /* ---------- Menu mobile ---------- */
    const menuToggle = document.getElementById('mobile-menu-toggle');
    const navLinks = document.getElementById('mainnav-links');
    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', () => {
            const isOpen = navLinks.classList.toggle('open');
            menuToggle.setAttribute('aria-expanded', String(isOpen));
        });
        document.addEventListener('click', (event) => {
            if (navLinks.classList.contains('open')
                && !navLinks.contains(event.target)
                && !menuToggle.contains(event.target)) {
                navLinks.classList.remove('open');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && navLinks.classList.contains('open')) {
                navLinks.classList.remove('open');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* ---------- Carrousel publicités ---------- */
    const adCarousel = document.getElementById('ad-carousel');
    if (adCarousel) {
        const slides = Array.from(adCarousel.querySelectorAll('.ad-carousel-slide'));
        const dots = Array.from(adCarousel.querySelectorAll('.ad-carousel-dot'));
        let current = 0;
        let timer = null;

        const showSlide = (index) => {
            if (!slides[current] || !slides[index]) return;
            slides[current].classList.remove('is-active');
            if (dots[current]) dots[current].classList.remove('is-active');
            current = index;
            slides[current].classList.add('is-active');
            if (dots[current]) dots[current].classList.add('is-active');
        };

        if (slides.length > 1) {
            const startTimer = () => {
                timer = window.setInterval(() => showSlide((current + 1) % slides.length), 5000);
            };
            const stopTimer = () => {
                if (timer) window.clearInterval(timer);
            };

            dots.forEach((dot, index) => {
                dot.addEventListener('click', () => {
                    showSlide(index);
                    stopTimer();
                    startTimer();
                });
            });

            adCarousel.addEventListener('mouseenter', stopTimer);
            adCarousel.addEventListener('mouseleave', startTimer);

            startTimer();
        }
    }

    /* ---------- Menu mobile admin ---------- */
    const adminMenuToggle = document.getElementById('admin-mobile-toggle');
    const adminSidebar = document.getElementById('admin-sidebar');
    if (adminMenuToggle && adminSidebar) {
        let adminBackdrop = document.querySelector('.admin-sidebar-backdrop');
        if (!adminBackdrop) {
            adminBackdrop = document.createElement('div');
            adminBackdrop.className = 'admin-sidebar-backdrop';
            document.body.appendChild(adminBackdrop);
        }

        const closeAdminSidebar = () => {
            adminSidebar.classList.remove('open');
            adminBackdrop.classList.remove('open');
            adminMenuToggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('no-scroll');
        };
        const openAdminSidebar = () => {
            adminSidebar.classList.add('open');
            adminBackdrop.classList.add('open');
            adminMenuToggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('no-scroll');
        };

        adminMenuToggle.addEventListener('click', () => {
            if (adminSidebar.classList.contains('open')) {
                closeAdminSidebar();
            } else {
                openAdminSidebar();
            }
        });
        adminBackdrop.addEventListener('click', closeAdminSidebar);
        document.addEventListener('click', (event) => {
            if (adminSidebar.classList.contains('open')
                && !adminSidebar.contains(event.target)
                && !adminMenuToggle.contains(event.target)) {
                closeAdminSidebar();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && adminSidebar.classList.contains('open')) {
                closeAdminSidebar();
            }
        });
    }

    /* ---------- Dropdown categories ---------- */
    document.querySelectorAll('[data-dropdown-toggle]').forEach((btn) => {
        const panel = document.getElementById(btn.getAttribute('data-dropdown-toggle'));
        if (!panel) return;

        btn.addEventListener('click', (event) => {
            event.stopPropagation();
            panel.classList.toggle('open');
        });
    });
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.dropdown-panel.open').forEach((panel) => {
            if (!panel.contains(event.target)) panel.classList.remove('open');
        });
    });

    /* ---------- Favoris ---------- */
    const favCountEl = document.getElementById('favorites-count');

    const refreshFavCount = () => {
        const list = readList(FAV_KEY);
        if (!favCountEl) return;
        favCountEl.textContent = String(list.length);
        favCountEl.hidden = list.length === 0;
    };

    document.querySelectorAll('.fav-btn[data-fav-id]').forEach((btn) => {
        const id = btn.getAttribute('data-fav-id');
        const favorites = readList(FAV_KEY);
        btn.classList.toggle('is-active', favorites.includes(id));

        btn.addEventListener('click', () => {
            const list = readList(FAV_KEY);
            const idx = list.indexOf(id);
            if (idx === -1) {
                list.push(id);
                btn.classList.add('is-active');
            } else {
                list.splice(idx, 1);
                btn.classList.remove('is-active');
            }
            writeList(FAV_KEY, list);
            refreshFavCount();
        });
    });
    refreshFavCount();

    /* ---------- Panier ---------- */
    const cartCountEl = document.getElementById('cart-count');

    const refreshCartCount = () => {
        const list = readList(CART_KEY);
        const total = list.reduce((sum, item) => sum + (item.qty || 1), 0);
        if (!cartCountEl) return;
        cartCountEl.textContent = String(total);
        cartCountEl.hidden = total === 0;
    };

    const showToast = (message) => {
        let toast = document.querySelector('.toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'toast';
            Object.assign(toast.style, {
                position: 'fixed', bottom: '24px', left: '50%', transform: 'translateX(-50%)',
                background: '#0e2a1c', color: '#fff', padding: '12px 20px', borderRadius: '999px',
                fontSize: '.85rem', fontWeight: '600', zIndex: '999', boxShadow: '0 8px 20px rgba(0,0,0,.25)',
                opacity: '0', transition: 'opacity .2s ease',
            });
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        requestAnimationFrame(() => { toast.style.opacity = '1'; });
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 1800);
    };

    const addToCart = (id, name, size) => {
        size = size || null;
        const list = readList(CART_KEY);
        const existing = list.find((item) => item.id === id && (item.size || null) === size);
        if (existing) {
            existing.qty = (existing.qty || 1) + 1;
        } else {
            list.push({ id, name, qty: 1, size });
        }
        writeList(CART_KEY, list);
        refreshCartCount();
        showToast(`${name} ajouté au panier`);
    };

    const getButtonSize = (btn) => {
        if (!btn.hasAttribute('data-requires-size')) return null;
        const picker = document.querySelector('.product-size-picker');
        const checked = picker ? picker.querySelector('input[name="product_size"]:checked') : null;
        return checked ? checked.value : null;
    };

    document.querySelectorAll('.add-cart-btn[data-id]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const size = getButtonSize(btn);
            if (btn.hasAttribute('data-requires-size') && !size) return;
            addToCart(btn.getAttribute('data-id'), btn.getAttribute('data-name') || 'Produit', size);
        });
    });

    document.querySelectorAll('.product-size-picker input[name="product_size"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            const btn = document.querySelector('.add-cart-btn[data-requires-size]');
            if (btn) btn.disabled = false;
        });
    });

    /* ---------- Vider le panier apres une commande reussie ---------- */
    const clearCartEl = document.getElementById('clear-cart-ids');
    if (clearCartEl) {
        try {
            const ids = JSON.parse(clearCartEl.textContent || '[]');
            if (Array.isArray(ids) && ids.length) {
                writeList(CART_KEY, readList(CART_KEY).filter((item) => !ids.includes(item.id)));
            }
        } catch (err) {
            /* ignore malformed data */
        }
    }

    refreshCartCount();

    /* ---------- Icones et helpers pour rendu JS (favoris / panier) ---------- */
    const HEART_SVG = '<svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>';
    const X_SVG = '<svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    const AVATAR_COLORS = ['#16a34a', '#2563eb', '#db2777', '#d97706', '#dc2626', '#7c3aed'];

    const avatarColor = (str) => {
        let hash = 0;
        for (let i = 0; i < str.length; i++) hash = (hash * 31 + str.charCodeAt(i)) >>> 0;
        return AVATAR_COLORS[hash % AVATAR_COLORS.length];
    };
    const escapeHtml = (str) => String(str).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
    const formatPrice = (amount) => `${amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`;
    const productThumbHtml = (p) => (p.image
        ? `<img src="${escapeHtml(p.image)}" alt="${escapeHtml(p.name)}" loading="lazy">`
        : escapeHtml(p.name.charAt(0).toUpperCase()));
    const productThumbStyle = (p) => (p.image ? '' : `background:${avatarColor(p.name)}`);

    /* ---------- Recherches recentes ---------- */
    const RECENT_SEARCH_KEY = 'manmarket_recent_searches';
    const MAX_RECENT_SEARCHES = 8;

    const addRecentSearch = (term) => {
        term = term.trim();
        if (!term) return;
        let list = readList(RECENT_SEARCH_KEY).filter((t) => t.toLowerCase() !== term.toLowerCase());
        list.unshift(term);
        writeList(RECENT_SEARCH_KEY, list.slice(0, MAX_RECENT_SEARCHES));
    };

    const currentSearchQuery = new URLSearchParams(window.location.search).get('q');
    if (currentSearchQuery) addRecentSearch(currentSearchQuery);

    document.querySelectorAll('.search-wrapper').forEach((wrapper) => {
        const form = wrapper.querySelector('.search-form');
        const input = form ? form.querySelector('input[name="q"]') : null;
        const panel = wrapper.querySelector('.recent-searches-panel');
        if (!form || !input || !panel) return;

        const renderPanel = () => {
            const list = readList(RECENT_SEARCH_KEY);
            if (!list.length) {
                panel.classList.remove('open');
                return;
            }
            panel.innerHTML = `
                <div class="recent-searches-title">
                    <span>Recherches récentes</span>
                    <button type="button" data-action="clear-all">Tout effacer</button>
                </div>
                ${list.map((term) => `
                    <div class="recent-search-item" data-term="${escapeHtml(term)}">
                        <span>${escapeHtml(term)}</span>
                        <button type="button" class="remove-search" data-term="${escapeHtml(term)}" aria-label="Supprimer cette recherche">${X_SVG}</button>
                    </div>
                `).join('')}
            `;
            panel.classList.add('open');
        };

        input.addEventListener('focus', () => {
            if (input.value.trim() === '') renderPanel();
        });
        input.addEventListener('input', () => {
            if (input.value.trim() === '') renderPanel();
            else panel.classList.remove('open');
        });

        panel.addEventListener('click', (event) => {
            const removeBtn = event.target.closest('.remove-search');
            const clearBtn = event.target.closest('[data-action="clear-all"]');
            const item = event.target.closest('.recent-search-item');

            if (removeBtn) {
                event.stopPropagation();
                const term = removeBtn.getAttribute('data-term');
                writeList(RECENT_SEARCH_KEY, readList(RECENT_SEARCH_KEY).filter((t) => t !== term));
                renderPanel();
                return;
            }
            if (clearBtn) {
                writeList(RECENT_SEARCH_KEY, []);
                panel.classList.remove('open');
                return;
            }
            if (item) {
                input.value = item.getAttribute('data-term');
                form.submit();
            }
        });

        document.addEventListener('click', (event) => {
            if (!wrapper.contains(event.target)) panel.classList.remove('open');
        });
    });

    /* ---------- Contact : sujet Commande + lieu de livraison ---------- */
    const deliveryFields = document.getElementById('delivery-location-fields');
    if (deliveryFields) {
        const subjectSelect = document.getElementById('subject');
        const citySelect = document.getElementById('delivery_city');
        const neighborhoodWrap = document.getElementById('delivery-neighborhood-wrap');
        const neighborhoodSelect = document.getElementById('delivery_neighborhood');
        let neighborhoodsByParent = {};
        try {
            neighborhoodsByParent = JSON.parse(deliveryFields.getAttribute('data-neighborhoods') || '{}');
        } catch (e) {
            neighborhoodsByParent = {};
        }

        const paymentChoiceField = document.getElementById('payment-choice-field');
        const paymentMethodField = document.getElementById('payment-method-field');
        const paymentChoiceRadios = document.querySelectorAll('input[name="payment_choice"]');

        const toggleDeliveryFields = () => {
            const isOrder = subjectSelect && subjectSelect.value === 'Commande';
            deliveryFields.style.display = isOrder ? '' : 'none';
            if (paymentChoiceField) paymentChoiceField.style.display = isOrder ? '' : 'none';
        };

        const togglePaymentMethodField = () => {
            if (!paymentMethodField) return;
            const selected = document.querySelector('input[name="payment_choice"]:checked');
            paymentMethodField.classList.toggle('is-hidden', !(selected && selected.value === 'online'));
        };

        paymentChoiceRadios.forEach((radio) => {
            radio.addEventListener('change', togglePaymentMethodField);
        });

        const orderSubtotal = parseInt(deliveryFields.getAttribute('data-subtotal') || '0', 10);
        const deliveryFeeValueEl = document.getElementById('delivery-fee-value');
        const orderGrandTotalValueEl = document.getElementById('order-grand-total-value');
        const formatFcfa = (amount) => `${amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`;

        const currentDeliveryFee = () => {
            const cityOption = citySelect.selectedOptions[0];
            const cityFee = cityOption ? parseInt(cityOption.getAttribute('data-fee') || '0', 10) : 0;
            const list = neighborhoodsByParent[citySelect.value] || [];
            if (list.length) {
                const neighborhoodOption = neighborhoodSelect.selectedOptions[0];
                if (neighborhoodOption && neighborhoodOption.value) {
                    const match = list.find((n) => String(n.id) === neighborhoodOption.value);
                    if (match) return parseInt(match.delivery_fee || 0, 10);
                }
            }
            return cityFee;
        };

        const updateDeliveryFeeDisplay = () => {
            if (!deliveryFeeValueEl || !orderGrandTotalValueEl) return;
            const fee = currentDeliveryFee();
            deliveryFeeValueEl.textContent = fee > 0 ? formatFcfa(fee) : 'Gratuit';
            orderGrandTotalValueEl.textContent = formatFcfa(orderSubtotal + fee);
        };

        const rebuildNeighborhoods = (selectedId) => {
            const list = neighborhoodsByParent[citySelect.value] || [];
            neighborhoodSelect.innerHTML = '<option value="">Choisir un quartier...</option>';
            list.forEach((n) => {
                const opt = document.createElement('option');
                opt.value = n.id;
                opt.textContent = n.name;
                if (selectedId && String(n.id) === String(selectedId)) opt.selected = true;
                neighborhoodSelect.appendChild(opt);
            });
            neighborhoodWrap.classList.toggle('is-hidden', !list.length);
            updateDeliveryFeeDisplay();
        };

        if (subjectSelect) {
            subjectSelect.addEventListener('change', toggleDeliveryFields);
        }
        if (citySelect) {
            citySelect.addEventListener('change', () => rebuildNeighborhoods(null));
        }
        if (neighborhoodSelect) {
            neighborhoodSelect.addEventListener('change', updateDeliveryFeeDisplay);
        }
    }

    /* ---------- Page favoris ---------- */
    const favoritesGrid = document.getElementById('favorites-grid');
    if (favoritesGrid) {
        const favoritesEmpty = document.getElementById('favorites-empty');
        const products = window.MM_PRODUCTS || {};

        const renderFavorites = () => {
            const rawIds = readList(FAV_KEY);
            const ids = rawIds.filter((id) => products[id]);

            if (JSON.stringify(ids) !== JSON.stringify(rawIds)) {
                writeList(FAV_KEY, ids);
                refreshFavCount();
            }

            favoritesGrid.innerHTML = ids.map((id) => {
                const p = products[id];
                const discountBadge = p.discount ? `<span class="badge-discount">-${p.discount}%</span>` : '';
                const oldPrice = p.originalPrice ? `<span class="price-old">${formatPrice(p.originalPrice)}</span>` : '';
                const cartBtn = p.shopOpen === false
                    ? `<button type="button" class="btn btn-outline-primary btn-sm btn-block" disabled>Boutique fermée</button>`
                    : p.stock <= 0
                        ? `<button type="button" class="btn btn-outline-primary btn-sm btn-block" disabled>Rupture de stock</button>`
                        : p.sizeType && p.sizeType !== 'none'
                            ? `<a href="/market/produit.php?slug=${escapeHtml(p.slug)}" class="btn btn-primary btn-sm btn-block">Voir le produit</a>`
                            : `<button type="button" class="btn btn-primary btn-sm btn-block add-cart-btn" data-id="${escapeHtml(id)}" data-name="${escapeHtml(p.name)}">Ajouter au panier</button>`;
                return `
                    <article class="product-card">
                        <button type="button" class="fav-btn is-active" data-fav-id="${escapeHtml(id)}" aria-label="Retirer des favoris">${HEART_SVG}</button>
                        ${discountBadge}
                        <div class="product-thumb product-thumb-avatar" style="${productThumbStyle(p)}">${productThumbHtml(p)}</div>
                        <h3>${escapeHtml(p.name)}</h3>
                        <span class="product-shop-link">${escapeHtml(p.shopName)}</span>
                        <div class="price-row">
                            <span class="price">${formatPrice(p.price)}</span>
                            ${oldPrice}
                        </div>
                        ${cartBtn}
                    </article>
                `;
            }).join('');

            favoritesGrid.querySelectorAll('.fav-btn[data-fav-id]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-fav-id');
                    writeList(FAV_KEY, readList(FAV_KEY).filter((item) => item !== id));
                    refreshFavCount();
                    renderFavorites();
                });
            });
            favoritesGrid.querySelectorAll('.add-cart-btn[data-id]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    addToCart(btn.getAttribute('data-id'), btn.getAttribute('data-name') || 'Produit');
                });
            });

            if (favoritesEmpty) favoritesEmpty.hidden = ids.length !== 0;
        };

        renderFavorites();
    }

    /* ---------- Page panier ---------- */
    const cartItemsEl = document.getElementById('cart-items');
    if (cartItemsEl) {
        const cartEmpty = document.getElementById('cart-empty');
        const cartSummary = document.getElementById('cart-summary');
        const cartSubtotalEl = document.getElementById('cart-subtotal');
        const checkoutBtn = document.getElementById('cart-checkout-btn');
        const products = window.MM_PRODUCTS || {};

        const sizeCap = (item) => {
            const p = products[item.id];
            if (!p) return 0;
            if (p.shopOpen === false) return 0;
            if (p.sizeStocks) {
                return item.size && Object.prototype.hasOwnProperty.call(p.sizeStocks, item.size) ? p.sizeStocks[item.size] : 0;
            }
            return p.stock;
        };

        const renderCart = () => {
            const rawList = readList(CART_KEY);
            const list = rawList
                .filter((item) => products[item.id] && item.qty > 0)
                .map((item) => ({ ...item, qty: Math.min(item.qty, sizeCap(item)) }))
                .filter((item) => item.qty > 0);

            if (JSON.stringify(list) !== JSON.stringify(rawList)) {
                writeList(CART_KEY, list);
            }

            let subtotal = 0;

            cartItemsEl.innerHTML = list.map((item) => {
                const p = products[item.id];
                const lineTotal = p.price * item.qty;
                subtotal += lineTotal;
                const maxQty = sizeCap(item);
                const atMaxStock = item.qty >= maxQty;
                const sizeLine = item.size ? `<span class="cart-item-size">Taille : ${escapeHtml(item.size)}</span>` : '';
                return `
                    <div class="cart-item" data-id="${escapeHtml(item.id)}" data-size="${escapeHtml(item.size || '')}">
                        <div class="product-thumb product-thumb-avatar" style="${productThumbStyle(p)}">${productThumbHtml(p)}</div>
                        <div class="cart-item-info">
                            <h3>${escapeHtml(p.name)}</h3>
                            ${sizeLine}
                            <span class="cart-item-shop">${escapeHtml(p.shopName)}</span>
                            <span class="cart-item-price">${formatPrice(p.price)}</span>
                            ${atMaxStock ? `<span class="cart-item-stock-note">Stock maximum atteint (${maxQty})</span>` : ''}
                        </div>
                        <div class="cart-item-qty">
                            <button type="button" class="qty-btn" data-action="dec" aria-label="Diminuer la quantité">&minus;</button>
                            <span>${item.qty}</span>
                            <button type="button" class="qty-btn" data-action="inc" aria-label="Augmenter la quantité" ${atMaxStock ? 'disabled' : ''}>+</button>
                        </div>
                        <strong class="cart-item-total">${formatPrice(lineTotal)}</strong>
                        <button type="button" class="cart-item-remove" aria-label="Retirer du panier">${X_SVG}</button>
                    </div>
                `;
            }).join('');

            cartItemsEl.querySelectorAll('.cart-item').forEach((row) => {
                const id = row.getAttribute('data-id');
                const size = row.getAttribute('data-size') || null;
                const matches = (item) => item.id === id && (item.size || null) === size;
                const maxQty = sizeCap({ id, size });
                const update = (mutate) => {
                    const l = readList(CART_KEY);
                    const item = l.find(matches);
                    if (!item) return;
                    mutate(item);
                    item.qty = Math.min(item.qty, maxQty);
                    writeList(CART_KEY, item.qty > 0 ? l : l.filter((i) => !matches(i)));
                    renderCart();
                };

                row.querySelector('[data-action="inc"]').addEventListener('click', () => update((item) => { item.qty += 1; }));
                row.querySelector('[data-action="dec"]').addEventListener('click', () => update((item) => { item.qty -= 1; }));
                row.querySelector('.cart-item-remove').addEventListener('click', () => {
                    writeList(CART_KEY, readList(CART_KEY).filter((i) => !matches(i)));
                    renderCart();
                });
            });

            refreshCartCount();
            if (cartEmpty) cartEmpty.hidden = list.length !== 0;
            if (cartSummary) cartSummary.hidden = list.length === 0;
            if (cartSubtotalEl) cartSubtotalEl.textContent = formatPrice(subtotal);

            if (checkoutBtn) {
                const itemsPayload = list.map((item) => {
                    const payload = { id: parseInt(item.id.replace('product-', ''), 10), qty: item.qty };
                    if (item.size) payload.size = item.size;
                    return payload;
                });
                checkoutBtn.href = `/market/commander.php?items=${encodeURIComponent(JSON.stringify(itemsPayload))}`;
            }
        };

        renderCart();
    }

    /* ---------- Page boutiques : recherche, filtre, tri ---------- */
    const shopsGrid = document.getElementById('shops-grid');
    if (shopsGrid) {
        const cards = Array.from(shopsGrid.querySelectorAll('.shop-card-lg'));
        const searchInput = document.getElementById('shop-search');
        const openOnly = document.getElementById('shop-open-only');
        const sortSelect = document.getElementById('shop-sort');
        const countEl = document.getElementById('shop-results-count');
        const emptyState = document.getElementById('shops-empty');
        const originalOrder = cards.slice();

        const applyFilters = () => {
            const query = normalize(searchInput ? searchInput.value.trim() : '');
            const mustBeOpen = openOnly ? openOnly.checked : false;
            let visibleCount = 0;

            cards.forEach((card) => {
                const haystack = normalize(`${card.dataset.name} ${card.dataset.neighborhood}`);
                const matchesQuery = query === '' || haystack.includes(query);
                const matchesOpen = !mustBeOpen || card.dataset.open === '1';
                const visible = matchesQuery && matchesOpen;
                card.style.display = visible ? '' : 'none';
                if (visible) visibleCount += 1;
            });

            if (countEl) countEl.textContent = String(visibleCount);
            if (emptyState) emptyState.hidden = visibleCount !== 0;
        };

        const applySort = () => {
            const mode = sortSelect ? sortSelect.value : 'default';
            let sorted = originalOrder;

            if (mode === 'rating') {
                sorted = originalOrder.slice().sort((a, b) => parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating));
            } else if (mode === 'name') {
                sorted = originalOrder.slice().sort((a, b) => a.dataset.name.localeCompare(b.dataset.name));
            }

            sorted.forEach((card) => shopsGrid.appendChild(card));
        };

        searchInput && searchInput.addEventListener('input', applyFilters);
        openOnly && openOnly.addEventListener('change', applyFilters);
        sortSelect && sortSelect.addEventListener('change', () => { applySort(); applyFilters(); });
    }

    /* ---------- Page offres : recherche, categorie, tri ---------- */
    const offersGrid = document.getElementById('offers-grid');
    if (offersGrid) {
        const cards = Array.from(offersGrid.querySelectorAll('.product-card'));
        const searchInput = document.getElementById('offer-search');
        const categorySelect = document.getElementById('offer-category');
        const sortSelect = document.getElementById('offer-sort');
        const countEl = document.getElementById('offer-results-count');
        const emptyState = document.getElementById('offers-empty');
        const originalOrder = cards.slice();

        const applyFilters = () => {
            const query = normalize(searchInput ? searchInput.value.trim() : '');
            const category = categorySelect ? categorySelect.value : '';
            let visibleCount = 0;

            cards.forEach((card) => {
                const matchesQuery = query === '' || normalize(card.dataset.name).includes(query);
                const matchesCategory = category === '' || card.dataset.category === category;
                const visible = matchesQuery && matchesCategory;
                card.style.display = visible ? '' : 'none';
                if (visible) visibleCount += 1;
            });

            if (countEl) countEl.textContent = String(visibleCount);
            if (emptyState) emptyState.hidden = visibleCount !== 0;
        };

        const applySort = () => {
            const mode = sortSelect ? sortSelect.value : 'default';
            let sorted = originalOrder;

            if (mode === 'discount') {
                sorted = originalOrder.slice().sort((a, b) => parseInt(b.dataset.discount, 10) - parseInt(a.dataset.discount, 10));
            } else if (mode === 'price-asc') {
                sorted = originalOrder.slice().sort((a, b) => parseInt(a.dataset.price, 10) - parseInt(b.dataset.price, 10));
            } else if (mode === 'price-desc') {
                sorted = originalOrder.slice().sort((a, b) => parseInt(b.dataset.price, 10) - parseInt(a.dataset.price, 10));
            } else if (mode === 'rating') {
                sorted = originalOrder.slice().sort((a, b) => parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating));
            }

            sorted.forEach((card) => offersGrid.appendChild(card));
        };

        searchInput && searchInput.addEventListener('input', applyFilters);
        categorySelect && categorySelect.addEventListener('change', applyFilters);
        sortSelect && sortSelect.addEventListener('change', () => { applySort(); applyFilters(); });
    }

    /* ---------- Page actualites : recherche ---------- */
    const newsGrid = document.getElementById('news-grid');
    if (newsGrid) {
        const cards = Array.from(newsGrid.querySelectorAll('.news-card-lg'));
        const searchInput = document.getElementById('news-search');
        const countEl = document.getElementById('news-results-count');
        const emptyState = document.getElementById('news-empty');

        const applyFilters = () => {
            const query = normalize(searchInput ? searchInput.value.trim() : '');
            let visibleCount = 0;

            cards.forEach((card) => {
                const haystack = normalize(`${card.dataset.title} ${card.dataset.excerpt}`);
                const visible = query === '' || haystack.includes(query);
                card.style.display = visible ? '' : 'none';
                if (visible) visibleCount += 1;
            });

            if (countEl) countEl.textContent = String(visibleCount);
            if (emptyState) emptyState.hidden = visibleCount !== 0;
        };

        searchInput && searchInput.addEventListener('input', applyFilters);
    }

    /* ---------- Formulaire de contact ---------- */
    const messageField = document.getElementById('message');
    const messageCount = document.getElementById('message-count');
    if (messageField && messageCount) {
        const updateCount = () => {
            messageCount.textContent = `${messageField.value.length} caractère(s)`;
        };
        messageField.addEventListener('input', updateCount);
        updateCount();
    }

    /* ---------- FAQ accordeon ---------- */
    document.querySelectorAll('.faq-item').forEach((item) => {
        const question = item.querySelector('.faq-question');
        question && question.addEventListener('click', () => {
            item.classList.toggle('open');
        });
    });

    /* ---------- Galerie photos produit (fiche produit) ---------- */
    const galleryMainImg = document.getElementById('product-gallery-main-img');
    if (galleryMainImg) {
        document.querySelectorAll('.product-gallery-thumb-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                galleryMainImg.src = btn.dataset.img;
                document.querySelectorAll('.product-gallery-thumb-btn').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
            });
        });
    }
})();
