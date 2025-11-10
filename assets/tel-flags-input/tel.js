document.addEventListener('DOMContentLoaded', async () => {
  const countries = [
    { code: 'CA', name: 'Canada', dial: '+1', flag: '🇨🇦', format: '+1 (999) 999-9999', max: 11 },
    { code: 'US', name: 'United States', dial: '+1', flag: '🇺🇸', format: '+1 (999) 999-9999', max: 11 },
    { code: 'UA', name: 'Україна', dial: '+380', flag: '🇺🇦', format: '+380 99 999 99 99', max: 12 },
    { code: 'PL', name: 'Polska', dial: '+48', flag: '🇵🇱', format: '+48 999 999 999', max: 11 },
    { code: 'DE', name: 'Deutschland', dial: '+49', flag: '🇩🇪', format: '+49 9999 999999', max: 12 },
    { code: 'FR', name: 'France', dial: '+33', flag: '🇫🇷', format: '+33 9 99 99 99 99', max: 11 },
    { code: 'ES', name: 'España', dial: '+34', flag: '🇪🇸', format: '+34 999 999 999', max: 11 },
    { code: 'IT', name: 'Italia', dial: '+39', flag: '🇮🇹', format: '+39 999 999 9999', max: 12 },
    { code: 'RO', name: 'România', dial: '+40', flag: '🇷🇴', format: '+40 999 999 999', max: 11 },
    { code: 'CZ', name: 'Česko', dial: '+420', flag: '🇨🇿', format: '+420 999 999 999', max: 12 },
    { code: 'SK', name: 'Slovensko', dial: '+421', flag: '🇸🇰', format: '+421 999 999 999', max: 12 },
    { code: 'HU', name: 'Magyarország', dial: '+36', flag: '🇭🇺', format: '+36 99 999 9999', max: 11 },
    { code: 'GB', name: 'United Kingdom', dial: '+44', flag: '🇬🇧', format: '+44 9999 999999', max: 12 },
    { code: 'NL', name: 'Nederland', dial: '+31', flag: '🇳🇱', format: '+31 99 999 9999', max: 11 },
    { code: 'BE', name: 'Belgique', dial: '+32', flag: '🇧🇪', format: '+32 999 99 99 99', max: 11 },
    { code: 'LT', name: 'Lietuva', dial: '+370', flag: '🇱🇹', format: '+370 999 99 999', max: 12 },
    { code: 'LV', name: 'Latvija', dial: '+371', flag: '🇱🇻', format: '+371 9999 9999', max: 12 },
    { code: 'EE', name: 'Eesti', dial: '+372', flag: '🇪🇪', format: '+372 9999 9999', max: 12 },
    { code: 'FI', name: 'Suomi', dial: '+358', flag: '🇫🇮', format: '+358 999 999999', max: 12 },
    { code: 'NO', name: 'Norge', dial: '+47', flag: '🇳🇴', format: '+47 999 99 999', max: 10 },
    { code: 'SE', name: 'Sverige', dial: '+46', flag: '🇸🇪', format: '+46 999 999 999', max: 11 },
    { code: 'DK', name: 'Danmark', dial: '+45', flag: '🇩🇰', format: '+45 99 99 99 99', max: 10 },
    { code: 'PT', name: 'Portugal', dial: '+351', flag: '🇵🇹', format: '+351 999 999 999', max: 12 },
    { code: 'CH', name: 'Schweiz', dial: '+41', flag: '🇨🇭', format: '+41 99 999 99 99', max: 11 },
    { code: 'GR', name: 'Ελλάδα', dial: '+30', flag: '🇬🇷', format: '+30 999 999 9999', max: 12 },
  ];

  let userCountry = 'CA';
  try {
    const res = await fetch('https://ipapi.co/json');
    const data = await res.json();
    if (data && data.country_code) userCountry = data.country_code;
  } catch {
    console.warn('Geo lookup failed, fallback CA');
  }

  const telInputs = document.querySelectorAll('.stm_woocommerce_checkout_billing input[type="tel"]');
  const onlyDigits = s => (s.match(/\d/g) || []).join('');

  function formatFromDigits(digits, format, dial) {
    let out = '';
    let j = 0;
    for (let i = 0; i < format.length; i++) {
      if (format[i] === '9') {
        if (j < digits.length) {
          out += digits[j++];
        } else {
          break;
        }
      } else {
        out += format[i];
      }
    }
    if (!out.startsWith(dial)) {
      out = (dial + ' ' + out).trim();
    }
    return out;
  }

  function digitCountBeforePos(str, pos) {
    return (str.slice(0, pos).match(/\d/g) || []).length;
  }

  function posForDigitIndex(format, dial, subDigits, digitIndex) {
    let placedDigits = 0;
    let built = '';
    for (let i = 0; i < format.length; i++) {
      if (format[i] === '9') {
        if (placedDigits < digitIndex) {
          built += subDigits[placedDigits] || '';
          placedDigits++;
        } else {
          return built.length + (built.startsWith(dial) ? 0 : (dial + ' ').length);
        }
      } else {
        built += format[i];
      }
    }
    const final = formatFromDigits(subDigits, format, dial);
    return final.length;
  }

  telInputs.forEach(input => {
    const wrapper = document.createElement('div');
    wrapper.className = 'phone-wrapper';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const selected = document.createElement('div');
    selected.className = 'phone-selected';
    wrapper.insertBefore(selected, input);

    const hiddenCodeInput = document.createElement('input');
    hiddenCodeInput.type = 'hidden';
    hiddenCodeInput.name = 'billing_phone_code';
    wrapper.appendChild(hiddenCodeInput);

    const dropdown = document.createElement('div');
    dropdown.className = 'phone-dropdown';
    const list = document.createElement('ul');
    list.className = 'phone-list';
    dropdown.appendChild(list);
    wrapper.appendChild(dropdown);

    countries.forEach(c => {
      const li = document.createElement('li');
      li.setAttribute('data-dial', c.dial);
      li.innerHTML = `${c.flag} ${c.name}`;
      list.appendChild(li);
    });

    const setCountry = (country) => {
      selected.innerHTML = `<span class="flag">${country.flag}</span>`;
      input.setAttribute('data-dial', country.dial);
      input.setAttribute('data-format', country.format);
      input.setAttribute('data-max', country.max);
      hiddenCodeInput.value = country.dial;
      input.value = country.dial + ' ';
      wrapper.classList.remove('open');
    };

    const current = countries.find(c => c.code === userCountry) || countries[0];
    setCountry(current);

    selected.addEventListener('click', (e) => {
      e.stopPropagation();
      wrapper.classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
      if (!wrapper.contains(e.target)) wrapper.classList.remove('open');
    });

    list.addEventListener('click', (e) => {
      const li = e.target.closest('li');
      if (!li) return;
      const country = countries.find(c => c.dial === li.getAttribute('data-dial'));
      if (country) setCountry(country);
    });

    input.addEventListener('paste', (e) => {
      e.preventDefault();
      const paste = (e.clipboardData || window.clipboardData).getData('text') || '';
      const dial = input.getAttribute('data-dial') || '';
      const format = input.getAttribute('data-format') || '';
      const max = parseInt(input.getAttribute('data-max'), 10) || 0;
      const dialDigits = onlyDigits(dial);
      const sub = onlyDigits(paste).slice(0, Math.max(0, max - dialDigits.length));
      input.value = formatFromDigits(sub, format, dial);
      input.setSelectionRange(input.value.length, input.value.length);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    input.addEventListener('beforeinput', (e) => {
      const dial = input.getAttribute('data-dial') || '';
      const format = input.getAttribute('data-format') || '';
      const max = parseInt(input.getAttribute('data-max'), 10) || 0;
      const dialDigits = onlyDigits(dial);

      if (!['deleteContentBackward', 'deleteContentForward', 'deleteByCut'].includes(e.inputType)) {
        return;
      }

      e.preventDefault();

      const curVal = input.value || '';
      const selStart = input.selectionStart ?? 0;
      const selEnd = input.selectionEnd ?? 0;

      const allDigits = onlyDigits(curVal);
      let sub = allDigits.slice(dialDigits.length);

      const apply = (newSub, caretDigitIndex) => {
        const formatted = formatFromDigits(newSub, format, dial);
        input.value = formatted;
        let pos;
        if (caretDigitIndex <= 0) {
          pos = (dial + ' ').length;
        } else {
          const tmpSub = newSub.slice(0, caretDigitIndex);
          pos = posForDigitIndex(format, dial, newSub, caretDigitIndex);
        }
        pos = Math.min(pos, input.value.length);
        input.setSelectionRange(pos, pos);
        input.dispatchEvent(new Event('input', { bubbles: true }));
      };

      if (selStart !== selEnd) {
        const selDigitsBefore = digitCountBeforePos(curVal, selStart);
        const selDigitsAfter = digitCountBeforePos(curVal, selEnd);
        const digitsInSelection = Math.max(0, selDigitsAfter - selDigitsBefore);

        const editableStart = (dial + ' ').length;
        if (selStart < editableStart) {
          if (selEnd <= editableStart) {
            return;
          }
        }

        const startIndex = Math.max(0, selDigitsBefore - dialDigits.length);
        const newSub = sub.slice(0, startIndex) + sub.slice(startIndex + digitsInSelection);
        apply(newSub, startIndex);
        return;
      }

      if (e.inputType === 'deleteContentBackward') {
        const digitsBeforeCaret = digitCountBeforePos(curVal, selStart);
        if (digitsBeforeCaret <= dialDigits.length) {
          return;
        }
        const removeIndex = digitsBeforeCaret - dialDigits.length - 1;
        const newSub = sub.slice(0, removeIndex) + sub.slice(removeIndex + 1);
        apply(newSub, removeIndex);
        return;
      }

      if (e.inputType === 'deleteContentForward') {
        const digitsBeforeCaret = digitCountBeforePos(curVal, selStart);
        const removeIndex = digitsBeforeCaret - dialDigits.length;
        if (removeIndex < 0 || removeIndex >= sub.length) {
          return;
        }
        const newSub = sub.slice(0, removeIndex) + sub.slice(removeIndex + 1);
        apply(newSub, removeIndex);
        return;
      }

      if (e.inputType === 'deleteByCut') {
        input.value = formatFromDigits('', format, dial);
        input.setSelectionRange((dial + ' ').length, (dial + ' ').length);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }
    });

    input.addEventListener('input', () => {
      const dial = input.getAttribute('data-dial') || '';
      const format = input.getAttribute('data-format') || '';
      const max = parseInt(input.getAttribute('data-max'), 10) || 0;
      const dialDigits = onlyDigits(dial);

      if (!input.value.startsWith(dial)) {
        const typedDigits = onlyDigits(input.value);
        let typedSub = typedDigits;
        if (typedDigits.startsWith(dialDigits)) {
          typedSub = typedDigits.slice(dialDigits.length);
        }
        typedSub = typedSub.slice(0, Math.max(0, max - dialDigits.length));
        input.value = formatFromDigits(typedSub, format, dial);
        input.setSelectionRange(input.value.length, input.value.length);
        return;
      }

      const allDigits = onlyDigits(input.value);
      let sub = allDigits.slice(dialDigits.length, dialDigits.length + Math.max(0, max - dialDigits.length));
      input.value = formatFromDigits(sub, format, dial);
      input.setSelectionRange(input.value.length, input.value.length);
    });

    input.addEventListener('blur', () => {
      const digits = onlyDigits(input.value || '');
      const max = parseInt(input.getAttribute('data-max'), 10) || 0;
      const dialDigits = onlyDigits(input.getAttribute('data-dial') || '');
      if (digits.length < (dialDigits.length + 1)) wrapper.classList.add('error');
      else wrapper.classList.remove('error');
    });
  });
});