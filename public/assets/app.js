(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const money = (cents) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
  const cartKey = 'thcli_cart_v1';

  const getCart = () => {
    try { return JSON.parse(localStorage.getItem(cartKey) || '[]'); } catch { return []; }
  };
  const saveCart = (cart) => {
    localStorage.setItem(cartKey, JSON.stringify(cart));
    renderCart();
  };
  const addItem = (item) => {
    const cart = getCart();
    const existing = cart.find((entry) => Number(entry.variant_id) === Number(item.variant_id));
    const limit = item.stock_quantity === null ? 10 : Math.min(10, Number(item.stock_quantity));
    if (existing) {
      existing.stock_quantity = item.stock_quantity;
      existing.quantity = Math.min(limit, existing.quantity + 1);
    }
    else cart.push({ ...item, quantity: 1 });
    saveCart(cart);
    openCart();
  };
  const updateItem = (variantId, delta) => {
    const cart = getCart();
    const item = cart.find((entry) => Number(entry.variant_id) === Number(variantId));
    if (!item) return;
    const limit = item.stock_quantity === null || item.stock_quantity === undefined ? 10 : Math.min(10, Number(item.stock_quantity));
    item.quantity = Math.min(limit, item.quantity + delta);
    saveCart(cart.filter((entry) => entry.quantity > 0));
  };
  const cartTotals = () => getCart().reduce((total, item) => total + Number(item.price) * Number(item.quantity), 0);

  function renderCart() {
    const cart = getCart();
    $$('[data-cart-count]').forEach((node) => node.textContent = String(cart.reduce((n, item) => n + item.quantity, 0)));
    $$('[data-cart-total], [data-checkout-total]').forEach((node) => node.textContent = money(cartTotals()));
    const markup = cart.length ? cart.map((item) => `
      <article class="cart-line">
        <div><strong>${escapeHtml(item.product_name)}</strong><small>${escapeHtml(item.variant_label)}</small></div>
        <div class="quantity-control"><button type="button" data-cart-minus="${item.variant_id}" aria-label="Reduce quantity">−</button><span>${item.quantity}</span><button type="button" data-cart-plus="${item.variant_id}" aria-label="Increase quantity">+</button></div>
        <b>${money(item.price * item.quantity)}</b>
      </article>`).join('') : '<div class="empty-cart"><i data-lucide="shopping-bag"></i><strong>Your cart is empty</strong><p>Add something from the menu to get started.</p></div>';
    $$('[data-cart-items], [data-checkout-items]').forEach((node) => node.innerHTML = markup);
    const json = $('[data-cart-json]');
    if (json) json.value = JSON.stringify(cart.map(({ variant_id, quantity }) => ({ variant_id, quantity })));
    if (window.lucide) window.lucide.createIcons();
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value || '');
    return div.innerHTML;
  }

  const drawer = $('[data-cart-drawer]');
  function openCart() { if (drawer) { drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); document.body.classList.add('no-scroll'); } }
  function closeCart() { if (drawer) { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); document.body.classList.remove('no-scroll'); } }

  document.addEventListener('click', (event) => {
    const open = event.target.closest('[data-cart-open]');
    const close = event.target.closest('[data-cart-close]');
    const plus = event.target.closest('[data-cart-plus]');
    const minus = event.target.closest('[data-cart-minus]');
    const addSelected = event.target.closest('[data-add-selected]');
    const reorder = event.target.closest('[data-reorder]');
    if (open) openCart();
    if (close) closeCart();
    if (plus) updateItem(plus.dataset.cartPlus, 1);
    if (minus) updateItem(minus.dataset.cartMinus, -1);
    if (addSelected) {
      const selected = $('[data-variant]:checked');
      if (selected) addItem({
        variant_id: Number(selected.value),
        product_id: Number(selected.dataset.productId),
        product_name: selected.dataset.productName,
        variant_label: selected.dataset.variantLabel,
        price: Number(selected.dataset.price),
        stock_quantity: selected.dataset.stockQuantity === '' ? null : Number(selected.dataset.stockQuantity),
      });
    }
    if (reorder) {
      try {
        const prior = JSON.parse(reorder.dataset.reorder || '[]');
        const cart = getCart();
        prior.forEach((item) => {
          const existing = cart.find((entry) => Number(entry.variant_id) === Number(item.variant_id));
          if (existing) existing.quantity = Math.min(10, existing.quantity + Number(item.quantity || 1));
          else cart.push(item);
        });
        saveCart(cart);
        openCart();
      } catch { alert('This order could not be added to the cart.'); }
    }
  });

  document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
  });

  const menuButton = $('[data-menu-toggle]');
  const mobileNav = $('[data-mobile-nav]');
  if (menuButton && mobileNav) menuButton.addEventListener('click', () => mobileNav.classList.toggle('open'));
  const adminMenu = $('[data-admin-menu]');
  const adminNav = $('[data-admin-nav]');
  if (adminMenu && adminNav) adminMenu.addEventListener('click', () => adminNav.classList.toggle('open'));
  const adminCollapse = $('[data-admin-collapse]');
  if (adminCollapse) {
    const setAdminSidebar = (collapsed, remember = true) => {
      document.documentElement.classList.toggle('admin-nav-collapsed', collapsed);
      adminCollapse.setAttribute('aria-expanded', String(!collapsed));
      adminCollapse.setAttribute('aria-label', collapsed ? 'Expand admin navigation' : 'Collapse admin navigation');
      adminCollapse.setAttribute('title', collapsed ? 'Expand navigation' : 'Collapse navigation');
      if (remember) {
        try { localStorage.setItem('thcli_admin_sidebar', collapsed ? 'collapsed' : 'expanded'); } catch {}
      }
    };
    setAdminSidebar(document.documentElement.classList.contains('admin-nav-collapsed'), false);
    adminCollapse.addEventListener('click', () => setAdminSidebar(!document.documentElement.classList.contains('admin-nav-collapsed')));
  }
  const reportRange = $('[data-report-range]');
  const syncReportRange = () => $$('[data-report-custom]').forEach((field) => { field.hidden = reportRange?.value !== 'custom'; });
  reportRange?.addEventListener('change', syncReportRange);
  syncReportRange();

  const ageGate = $('[data-age-gate]');
  if (ageGate && localStorage.getItem('thcli_age_verified') !== 'yes') {
    ageGate.hidden = false;
    document.body.classList.add('no-scroll');
    $('[data-age-yes]', ageGate)?.addEventListener('click', () => {
      localStorage.setItem('thcli_age_verified', 'yes');
      ageGate.hidden = true;
      document.body.classList.remove('no-scroll');
    });
  }

  function updateFulfillment() {
    const selected = $('[name="fulfillment"]:checked');
    const delivery = selected?.value === 'delivery';
    const fields = $('[data-delivery-fields]');
    if (fields) {
      fields.hidden = !delivery;
      $$('input', fields).forEach((input) => input.required = delivery && ['address1', 'city', 'postal_code'].includes(input.name));
    }
    const pickupPayment = $('.payment-pickup');
    if (pickupPayment) {
      pickupPayment.hidden = delivery;
      const input = $('input', pickupPayment);
      if (delivery && input.checked) $('[name="payment_method"][value="manual_prepaid"]')?.click();
    }
  }
  $$('[name="fulfillment"]').forEach((input) => input.addEventListener('change', updateFulfillment));
  updateFulfillment();

  $('[data-checkout-form]')?.addEventListener('submit', (event) => {
    const cart = getCart();
    if (!cart.length) {
      event.preventDefault();
      alert('Your cart is empty. Add products before checking out.');
    }
  });

  $$('[data-admin-filter-auto]').forEach((select) => select.addEventListener('change', () => select.form?.requestSubmit()));

  const variantEditors = $('[data-variant-editors]');
  const variantTemplate = $('[data-variant-template]');
  const variantCount = $('[data-variant-count]');
  function reindexVariants() {
    if (!variantEditors) return;
    const rows = $$('[data-variant-row]', variantEditors);
    rows.forEach((row, index) => {
      $('[data-variant-number]', row).textContent = `Option ${index + 1}`;
      $$('[name]', row).forEach((input) => {
        input.name = input.name.replace(/variants\[(?:\d+|__INDEX__)\]/, `variants[${index}]`);
      });
      const remove = $('[data-remove-variant]', row);
      if (remove) remove.disabled = rows.length === 1;
    });
    if (variantCount) variantCount.textContent = `${rows.length} ${rows.length === 1 ? 'option' : 'options'}`;
  }
  function syncVariantStock(row) {
    const quantity = $('[data-stock-quantity]', row);
    const status = $('[data-stock-status]', row);
    const help = $('[data-stock-status-help]', row);
    if (!quantity || !status) return;
    const tracked = quantity.value.trim() !== '';
    if (tracked) {
      const amount = Math.max(0, Number.parseInt(quantity.value, 10) || 0);
      status.value = amount === 0 ? 'sold_out' : (amount <= 5 ? 'low_stock' : 'in_stock');
    }
    status.disabled = tracked;
    if (help) help.textContent = tracked ? 'Automatic from quantity' : 'Manual when quantity is blank';
  }
  $('[data-add-variant]')?.addEventListener('click', () => {
    if (!variantEditors || !variantTemplate) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = variantTemplate.innerHTML.replaceAll('__INDEX__', String($$('[data-variant-row]', variantEditors).length)).trim();
    const row = wrapper.firstElementChild;
    if (!row) return;
    variantEditors.append(row);
    reindexVariants();
    syncVariantStock(row);
    $('input[name$="[label]"]', row)?.focus();
    window.lucide?.createIcons({ attrs: { 'stroke-width': 1.8 } });
  });
  variantEditors?.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove-variant]');
    if (!remove || remove.disabled) return;
    remove.closest('[data-variant-row]')?.remove();
    reindexVariants();
  });
  variantEditors?.addEventListener('input', (event) => {
    if (!event.target.matches('[data-stock-quantity]')) return;
    syncVariantStock(event.target.closest('[data-variant-row]'));
  });
  reindexVariants();
  if (variantEditors) $$('[data-variant-row]', variantEditors).forEach(syncVariantStock);

  function initPos() {
    const root = $('[data-pos-app]');
    if (!root) return;
    const storageKey = 'thcli_pos_cart_v1';
    const taxEnabled = root.dataset.taxEnabled === '1';
    const taxRate = Math.max(0, Number(root.dataset.taxRate) || 0);
    const discountEnabled = root.dataset.discountEnabled === '1';
    const canComplete = root.dataset.canComplete === '1' && root.dataset.posEnabled === '1';
    const form = $('[data-pos-form]', root);
    const discountInput = $('[data-pos-discount-input]', root);
    const catalogNode = $('[data-pos-catalog-json]', root);
    const modal = $('[data-pos-modal]', root);
    const cartPanel = $('[data-pos-cart-panel]', root);
    const cartDock = $('[data-pos-cart-open]', root);
    const customerSearch = $('[data-pos-customer-search]', root);
    const customerResults = $('[data-pos-customer-results]', root);
    const customerIdInput = $('[data-pos-customer-id]', root);
    const skipCustomer = $('[data-pos-skip-customer]', root);
    let customerSearchTimer = null;
    let customerSearchRequest = 0;
    let catalog = [];
    try { catalog = JSON.parse(catalogNode?.textContent || '[]'); } catch { catalog = []; }
    const products = new Map(catalog.map((product) => [Number(product.id), product]));
    const modalSelections = new Map();
    let activeProduct = null;
    let selectedVariantId = null;
    let modalQuantity = 1;
    let lastProductTrigger = null;
    let modalCloseTimer = null;

    const read = () => {
      try { return JSON.parse(sessionStorage.getItem(storageKey) || '[]'); } catch { return []; }
    };
    const write = (cart) => {
      sessionStorage.setItem(storageKey, JSON.stringify(cart));
      render();
    };
    const totalQuantity = (cart) => cart.reduce((sum, item) => sum + Number(item.quantity), 0);

    function totals(cart) {
      const subtotal = cart.reduce((sum, item) => sum + Number(item.price) * Number(item.quantity), 0);
      const discountPercent = discountEnabled ? Math.max(0, Number(discountInput?.value) || 0) : 0;
      const discount = Math.min(subtotal, Math.round(subtotal * discountPercent / 100));
      const tax = taxEnabled ? Math.round((subtotal - discount) * taxRate / 100) : 0;
      return { subtotal, discount, tax, total: subtotal - discount + tax };
    }

    function render() {
      const cart = read();
      const { subtotal, discount, tax, total } = totals(cart);
      const items = $('[data-pos-cart-items]', root);
      if (items) items.innerHTML = cart.length ? cart.map((item) => `
        <article class="pos-cart-line">
          <div><strong>${escapeHtml(item.product_name)}</strong><small>${escapeHtml(item.variant_label)}${item.stock_quantity === null ? '' : ` · ${Number(item.stock_quantity)} available`}</small></div>
          <b>${money(Number(item.price) * Number(item.quantity))}</b>
          <div class="pos-cart-quantity"><button type="button" data-pos-minus="${Number(item.variant_id)}" aria-label="Reduce quantity">−</button><span>${Number(item.quantity)}</span><button type="button" data-pos-plus="${Number(item.variant_id)}" aria-label="Increase quantity">+</button></div>
        </article>`).join('') : '<div class="empty-cart"><i data-lucide="shopping-basket"></i><strong>Cart is empty</strong><p>Tap a product to choose an option.</p></div>';
      const values = {
        '[data-pos-cart-count]': String(totalQuantity(cart)),
        '[data-pos-subtotal]': money(subtotal),
        '[data-pos-discount]': `−${money(discount)}`,
        '[data-pos-tax]': money(tax),
        '[data-pos-total]': money(total),
        '[data-pos-dock-count]': String(totalQuantity(cart)),
        '[data-pos-dock-total]': money(total),
      };
      Object.entries(values).forEach(([selector, value]) => { const node = $(selector, root); if (node) node.textContent = value; });
      const discountRow = $('[data-pos-discount-row]', root);
      if (discountRow) discountRow.hidden = discount <= 0;
      const json = $('[data-pos-cart-json]', root);
      if (json) json.value = JSON.stringify(cart.map(({ variant_id, quantity }) => ({ variant_id, quantity })));
      const submit = $('[data-pos-submit]', root);
      if (submit) submit.disabled = !cart.length || !canComplete;
      cartDock?.classList.toggle('has-items', cart.length > 0);
      if (activeProduct) renderModalOptions();
      window.lucide?.createIcons({ attrs: { 'stroke-width': 1.8 } });
    }

    function addItem(product, variant, quantity) {
      const cart = read();
      const variantId = Number(variant.id);
      const stock = variant.stock_quantity === null ? null : Number(variant.stock_quantity);
      const limit = stock === null ? 10 : Math.min(10, stock);
      const existing = cart.find((item) => Number(item.variant_id) === variantId);
      if (existing) existing.quantity = Math.min(limit, Number(existing.quantity) + Number(quantity));
      else cart.push({ variant_id: variantId, product_name: product.name, variant_label: variant.label, price: Number(variant.effective_price_cents), stock_quantity: stock, quantity: Math.min(limit, Number(quantity)) });
      write(cart);
      closeProductModal();
    }

    function change(variantId, delta) {
      const cart = read();
      const item = cart.find((entry) => Number(entry.variant_id) === Number(variantId));
      if (!item) return;
      const limit = item.stock_quantity === null ? 10 : Math.min(10, Number(item.stock_quantity));
      item.quantity = Math.min(limit, Number(item.quantity) + delta);
      write(cart.filter((entry) => entry.quantity > 0));
    }

    function selectedVariant() {
      return activeProduct?.variants?.find((variant) => Number(variant.id) === Number(selectedVariantId)) || activeProduct?.variants?.[0] || null;
    }

    function variantLimit(variant) {
      if (!variant) return 1;
      return variant.stock_quantity === null ? 10 : Math.max(1, Math.min(10, Number(variant.stock_quantity)));
    }

    function renderModalOptions() {
      if (!activeProduct || !modal) return;
      const cart = read();
      const variantsNode = $('[data-pos-modal-variants]', modal);
      if (variantsNode) variantsNode.innerHTML = activeProduct.variants.map((variant) => {
        const selected = Number(variant.id) === Number(selectedVariantId);
        const stock = variant.stock_quantity === null ? 'Stock not tracked · Available' : `${Number(variant.stock_quantity)} in stock`;
        const stockClass = variant.stock_quantity !== null && Number(variant.stock_quantity) <= 5 ? ' low' : '';
        const inCart = cart.find((item) => Number(item.variant_id) === Number(variant.id));
        const detail = [variant.flavors, variant.sku ? `SKU ${variant.sku}` : ''].filter(Boolean).join(' · ');
        const regularPrice = Number(variant.price_cents);
        const salePrice = variant.sale_price_cents === null ? null : Number(variant.sale_price_cents);
        return `<button type="button" class="${selected ? 'selected' : ''}" data-pos-modal-variant="${Number(variant.id)}" aria-selected="${selected}">
          <span class="pos-modal-variant-check"><i data-lucide="${selected ? 'check' : 'circle'}"></i></span>
          <span class="pos-modal-variant-copy"><strong>${escapeHtml(variant.label)}</strong>${detail ? `<small>${escapeHtml(detail)}</small>` : ''}<small class="pos-modal-stock${stockClass}">${escapeHtml(stock)}${inCart ? ` · ${Number(inCart.quantity)} in cart` : ''}</small></span>
          <span class="pos-modal-price">${salePrice !== null ? `<del>${money(regularPrice)}</del>` : ''}<strong>${money(Number(variant.effective_price_cents))}</strong></span>
        </button>`;
      }).join('');
      updateModalFooter();
      window.lucide?.createIcons({ attrs: { 'stroke-width': 1.8 } });
    }

    function updateModalFooter() {
      const variant = selectedVariant();
      if (!variant || !modal) return;
      modalQuantity = Math.max(1, Math.min(variantLimit(variant), Number(modalQuantity) || 1));
      const quantityNode = $('[data-pos-modal-quantity]', modal);
      const totalNode = $('[data-pos-modal-add-total]', modal);
      const addLabel = $('[data-pos-modal-add] span', modal);
      const minus = $('[data-pos-modal-minus]', modal);
      const plus = $('[data-pos-modal-plus]', modal);
      if (quantityNode) quantityNode.textContent = String(modalQuantity);
      if (totalNode) totalNode.textContent = money(Number(variant.effective_price_cents) * modalQuantity);
      if (addLabel) addLabel.textContent = modalQuantity === 1 ? 'Add to cart' : `Add ${modalQuantity} to cart`;
      if (minus) minus.disabled = modalQuantity <= 1;
      if (plus) plus.disabled = modalQuantity >= variantLimit(variant);
    }

    function openProductModal(productId, trigger) {
      const product = products.get(Number(productId));
      if (!product || !modal) return;
      activeProduct = product;
      lastProductTrigger = trigger;
      const saved = modalSelections.get(Number(product.id));
      selectedVariantId = Number(saved?.variantId || product.variants[0]?.id);
      modalQuantity = Number(saved?.quantity || 1);
      const imageNode = $('[data-pos-modal-image]', modal);
      if (imageNode) imageNode.innerHTML = product.image_url ? `<img src="${escapeHtml(product.image_url)}" alt="">` : '<span class="pos-image-fallback"><i data-lucide="package-open"></i></span>';
      const values = {
        '[data-pos-modal-brand]': product.brand || product.category_name,
        '[data-pos-modal-title]': product.name,
        '[data-pos-modal-meta]': [product.category_name, product.strain_type].filter(Boolean).join(' · '),
        '[data-pos-modal-potency]': product.potency || '',
      };
      Object.entries(values).forEach(([selector, value]) => { const node = $(selector, modal); if (node) node.textContent = value; });
      const potencyNode = $('[data-pos-modal-potency]', modal);
      if (potencyNode) potencyNode.hidden = !product.potency;
      renderModalOptions();
      clearTimeout(modalCloseTimer);
      modal.hidden = false;
      requestAnimationFrame(() => modal.classList.add('open'));
      document.body.classList.add('pos-modal-open');
      $('[data-pos-modal-close]', modal)?.focus({ preventScroll: true });
    }

    function closeProductModal() {
      if (!modal || modal.hidden) return;
      if (activeProduct) modalSelections.set(Number(activeProduct.id), { variantId: selectedVariantId, quantity: modalQuantity });
      modal.classList.remove('open');
      document.body.classList.remove('pos-modal-open');
      modalCloseTimer = setTimeout(() => { modal.hidden = true; }, 180);
      lastProductTrigger?.focus({ preventScroll: true });
    }

    function openPosCart() {
      cartPanel?.classList.add('open');
      document.body.classList.add('pos-cart-open');
      cartDock?.setAttribute('aria-expanded', 'true');
      $('[data-pos-cart-close]', cartPanel)?.focus({ preventScroll: true });
    }

    function closePosCart() {
      cartPanel?.classList.remove('open');
      document.body.classList.remove('pos-cart-open');
      cartDock?.setAttribute('aria-expanded', 'false');
    }

    function setCustomerSkipped(skipped) {
      const fields = $('[data-pos-customer-fields]', root);
      $$('input,textarea', fields || root).forEach((field) => {
        if (!fields?.contains(field)) return;
        field.disabled = skipped;
      });
      if (customerSearch) customerSearch.disabled = skipped;
      if (customerIdInput) customerIdInput.disabled = skipped;
      $('[data-pos-customer-capture]', root)?.classList.toggle('anonymous', skipped);
      if (skipped && customerResults) customerResults.hidden = true;
    }

    function chooseCustomer(customer) {
      if (customerIdInput) customerIdInput.value = String(customer.id || '');
      const values = {
        '[name="customer_name"]': customer.name || '',
        '[name="customer_phone"]': customer.phone || '',
        '[name="customer_email"]': customer.email || '',
      };
      Object.entries(values).forEach(([selector, value]) => { const input = $(selector, root); if (input) input.value = value; });
      const marketing = $('[name="marketing_opt_in"]', root);
      if (marketing) marketing.checked = Number(customer.marketing_opt_in) === 1;
      if (customerSearch) customerSearch.value = customer.name || '';
      if (customerResults) customerResults.hidden = true;
      if (skipCustomer) skipCustomer.checked = false;
      setCustomerSkipped(false);
    }

    async function searchCustomers() {
      if (!customerSearch || !customerResults) return;
      const query = customerSearch.value.trim();
      const request = ++customerSearchRequest;
      if (query.length < 2) {
        customerResults.hidden = true;
        customerResults.innerHTML = '';
        return;
      }
      customerResults.hidden = false;
      customerResults.innerHTML = '<span class="pos-customer-loading">Searching customers…</span>';
      try {
        const response = await fetch(`${window.SHOP.base}/admin/customers/search?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Customer search failed');
        const payload = await response.json();
        if (request !== customerSearchRequest) return;
        customerResults.innerHTML = payload.customers?.length ? payload.customers.map((customer) => `
          <button type="button" data-pos-customer-choice="${escapeHtml(encodeURIComponent(JSON.stringify(customer)))}">
            <span><strong>${escapeHtml(customer.name)}</strong><small>${escapeHtml(customer.phone || customer.email || 'Customer record')}</small></span><i data-lucide="arrow-up-right"></i>
          </button>`).join('') : '<span class="pos-customer-loading">No matching customer. Enter the new details below.</span>';
        window.lucide?.createIcons({ attrs: { 'stroke-width': 1.8 } });
      } catch {
        if (request === customerSearchRequest) customerResults.innerHTML = '<span class="pos-customer-loading">Customer search is unavailable. New details can still be saved.</span>';
      }
    }

    function filterProducts() {
      const query = ($('[data-pos-search]', root)?.value || '').trim().toLowerCase();
      const active = $('[data-pos-category].active', root)?.dataset.posCategory || '';
      let visible = 0;
      $$('[data-pos-product]', root).forEach((card) => {
        const show = (!active || card.dataset.category === active) && (!query || (card.dataset.search || '').includes(query));
        card.hidden = !show;
        if (show) visible += 1;
      });
      const count = $('[data-pos-visible-count]', root);
      if (count) count.textContent = String(visible);
      const empty = $('[data-pos-no-results]', root);
      if (empty) empty.hidden = visible !== 0;
    }

    root.addEventListener('click', (event) => {
      const productButton = event.target.closest('[data-pos-open-product]');
      const plus = event.target.closest('[data-pos-plus]');
      const minus = event.target.closest('[data-pos-minus]');
      const clear = event.target.closest('[data-pos-clear]');
      const category = event.target.closest('[data-pos-category]');
      const modalVariant = event.target.closest('[data-pos-modal-variant]');
      const modalMinus = event.target.closest('[data-pos-modal-minus]');
      const modalPlus = event.target.closest('[data-pos-modal-plus]');
      const modalAdd = event.target.closest('[data-pos-modal-add]');
      const modalClose = event.target.closest('[data-pos-modal-close]');
      const cartOpen = event.target.closest('[data-pos-cart-open]');
      const cartClose = event.target.closest('[data-pos-cart-close]');
      const customerChoice = event.target.closest('[data-pos-customer-choice]');
      if (productButton) openProductModal(productButton.dataset.posOpenProduct, productButton);
      if (plus) change(plus.dataset.posPlus, 1);
      if (minus) change(minus.dataset.posMinus, -1);
      if (clear) write([]);
      if (category) {
        $$('[data-pos-category]', root).forEach((button) => button.classList.toggle('active', button === category));
        filterProducts();
      }
      if (modalVariant) {
        selectedVariantId = Number(modalVariant.dataset.posModalVariant);
        modalQuantity = 1;
        renderModalOptions();
      }
      if (modalMinus) { modalQuantity -= 1; updateModalFooter(); }
      if (modalPlus) { modalQuantity += 1; updateModalFooter(); }
      if (modalAdd) {
        const variant = selectedVariant();
        if (activeProduct && variant) addItem(activeProduct, variant, modalQuantity);
      }
      if (modalClose || event.target === modal) closeProductModal();
      if (cartOpen) openPosCart();
      if (cartClose) closePosCart();
      if (customerChoice) {
        try { chooseCustomer(JSON.parse(decodeURIComponent(customerChoice.dataset.posCustomerChoice))); } catch {}
      }
    });
    $('[data-pos-search]', root)?.addEventListener('input', filterProducts);
    discountInput?.addEventListener('input', render);
    customerSearch?.addEventListener('input', () => {
      clearTimeout(customerSearchTimer);
      customerSearchTimer = setTimeout(searchCustomers, 220);
    });
    skipCustomer?.addEventListener('change', () => setCustomerSkipped(skipCustomer.checked));
    $$('[data-pos-customer-fields] input', root).forEach((input) => input.addEventListener('input', () => {
      if (customerIdInput && !input.matches('[name="marketing_opt_in"]')) customerIdInput.value = '';
    }));
    form?.addEventListener('submit', (event) => {
      if (!read().length) {
        event.preventDefault();
        alert('Add at least one product before completing the sale.');
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (modal && !modal.hidden) closeProductModal();
      else closePosCart();
    });
    window.addEventListener('resize', () => {
      if (window.matchMedia('(min-width: 821px) and (orientation: landscape)').matches) closePosCart();
    });
    filterProducts();
    setCustomerSkipped(skipCustomer?.checked || false);
    render();
  }

  initPos();

  renderCart();
  window.addEventListener('load', () => window.lucide?.createIcons({ attrs: { 'stroke-width': 1.8 } }));
})();
