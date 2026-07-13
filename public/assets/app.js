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
    if (existing) existing.quantity = Math.min(10, existing.quantity + 1);
    else cart.push({ ...item, quantity: 1 });
    saveCart(cart);
    openCart();
  };
  const updateItem = (variantId, delta) => {
    const cart = getCart();
    const item = cart.find((entry) => Number(entry.variant_id) === Number(variantId));
    if (!item) return;
    item.quantity += delta;
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

  const menuButton = $('[data-menu-toggle]');
  const mobileNav = $('[data-mobile-nav]');
  if (menuButton && mobileNav) menuButton.addEventListener('click', () => mobileNav.classList.toggle('open'));
  const adminMenu = $('[data-admin-menu]');
  const adminNav = $('[data-admin-nav]');
  if (adminMenu && adminNav) adminMenu.addEventListener('click', () => adminNav.classList.toggle('open'));

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

  const productSearch = $('[data-admin-product-search]');
  if (productSearch) productSearch.addEventListener('input', () => {
    const query = productSearch.value.trim().toLowerCase();
    $$('[data-admin-product]').forEach((row) => row.hidden = query && !row.dataset.name.includes(query));
  });

  renderCart();
  window.addEventListener('load', () => window.lucide?.createIcons({ attrs: { 'stroke-width': 1.8 } }));
})();
