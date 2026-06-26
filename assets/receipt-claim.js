(() => {
  const forms = document.querySelectorAll('[data-receipt-claim]');

  forms.forEach((form) => {
    const message = form.querySelector('[data-message]');
    const radios = form.querySelectorAll('[name="loyaltyType"]');
    const fields = form.querySelectorAll('input');

    const getLoyaltyType = () => {
      const checked = form.querySelector('[name="loyaltyType"]:checked')?.value;

      if (checked) {
        return checked;
      }

      const activeField = Array.from(form.querySelectorAll('[data-loyalty-field]')).find((field) => !field.hidden);
      return activeField?.dataset.loyaltyField || '';
    };

    const clearInvalid = () => {
      fields.forEach((field) => {
        field.removeAttribute('aria-invalid');
      });
    };

    const updateFields = () => {
      const active = getLoyaltyType();

      form.querySelectorAll('[data-loyalty-field]').forEach((field) => {
        const isActive = field.dataset.loyaltyField === active;
        field.hidden = !isActive;
        field.querySelectorAll('input').forEach((input) => {
          input.required = isActive;
          input.removeAttribute('aria-invalid');
          if (!isActive) input.value = '';
        });
      });
    };

    const setMessage = (text, type = '') => {
      message.textContent = text || '';
      message.dataset.type = type;
    };

    const setFieldError = (input, text) => {
      input.setAttribute('aria-invalid', 'true');
      input.focus();
      setMessage(text, 'error');
    };

    const normalizePhone = (phone) => {
      const value = phone.trim();

      if (/^\+\d{8,15}$/.test(value)) {
        return value;
      }

      const digits = value.replace(/\D+/g, '');

      if (digits.length === 10) {
        return `+1${digits}`;
      }

      if (digits.length >= 8 && digits.length <= 15) {
        return `+${digits}`;
      }

      return '';
    };

    const validate = () => {
      clearInvalid();

      const tabRef = form.querySelector('[name="tabRef"]');
      const loyaltyType = getLoyaltyType();

      if (!tabRef || !tabRef.value.trim()) {
        setFieldError(tabRef, 'Please enter the receipt or check number from your receipt.');
        return false;
      }

      if (!loyaltyType) {
        setMessage('Please choose one loyalty identifier.', 'error');
        return false;
      }

      if (loyaltyType === 'cardNumber') {
        const cardNumber = form.querySelector('[name="cardNumber"]');
        const digits = (cardNumber?.value || '').replace(/\D+/g, '');

        if (!digits) {
          setFieldError(cardNumber, 'Please enter your loyalty card number.');
          return false;
        }

        cardNumber.value = digits;
      }

      if (loyaltyType === 'mobilePhone') {
        const mobilePhone = form.querySelector('[name="mobilePhone"]');
        const normalized = normalizePhone(mobilePhone?.value || '');

        if (!normalized) {
          setFieldError(mobilePhone, 'Please enter a valid phone number.');
          return false;
        }

        mobilePhone.value = normalized;
      }

      if (loyaltyType === 'email') {
        const email = form.querySelector('[name="email"]');
        const value = (email?.value || '').trim();

        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
          setFieldError(email, 'Please enter a valid email address.');
          return false;
        }

        email.value = value;
      }

      return true;
    };

    const buildPayload = () => {
      const payload = {
        tabRef: form.querySelector('[name="tabRef"]')?.value.trim() || '',
        loyaltyType: getLoyaltyType(),
        companyWebsite: form.querySelector('[name="companyWebsite"]')?.value || ''
      };

      if (payload.loyaltyType === 'cardNumber') {
        payload.cardNumber = form.querySelector('[name="cardNumber"]')?.value || '';
      }

      if (payload.loyaltyType === 'mobilePhone') {
        payload.mobilePhone = form.querySelector('[name="mobilePhone"]')?.value || '';
      }

      if (payload.loyaltyType === 'email') {
        payload.email = form.querySelector('[name="email"]')?.value || '';
      }

      return payload;
    };

    fields.forEach((field) => {
      field.addEventListener('input', () => {
        field.removeAttribute('aria-invalid');
      });
    });

    radios.forEach((radio) => radio.addEventListener('change', updateFields));
    updateFields();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setMessage('');

      if (!validate()) {
        return;
      }

      const button = form.querySelector('[type="submit"]');
      const payload = buildPayload();

      button.disabled = true;
      button.dataset.originalText = button.dataset.originalText || button.textContent;
      button.textContent = 'Submitting...';

      try {
        const response = await fetch(ReceiptClaim.endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ReceiptClaim.nonce
          },
          body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
          console.log(response); console.log(data);
          setMessage(data.message || 'Unable to submit receipt claim.', 'error');
        } else {
          setMessage(data.message || 'Receipt claimed successfully.', 'success');
          form.reset();
          updateFields();
        }
      } catch (error) {
        console.log(response); console.log(data);
        setMessage('Unable to submit receipt claim. Please try again.', 'error');
      } finally {
        button.disabled = false;
        button.textContent = button.dataset.originalText;
      }
    });
  });
})();
