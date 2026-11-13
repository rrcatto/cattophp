/*
  CattoPHP's components in the browser, loaded on every page after htmx and before the project's own scripts: windows,
  menus and the narrow-screen navigation, messages that dismiss themselves, confirmations, the tree picker, feature cards
  that send their form, a list's lookups, tables that fit their columns and explain cut-short cells, the pager that fits
  its row, figures that fit their cards, a row's tooltip, links that open a closed section, hex colours with their swatch,
  choices that fill a field, and live pages that refresh themselves without disturbing the reader. Everything works
  without it; it only makes the server-drawn pages easier to use. A project's own script reaches the helpers through
  window.CattoPHP (ready, within, keepScrollerPlace) and adds to the tree picker with window.CattoTreeSelect.extend().
*/
(() => {
  'use strict';

  const ready = callback => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, {once:true});
    else callback();
  };

  /*
    The navigation keeps its scroll position across a page load.

    Every link is an ordinary link, so following one is a full document load and the navigation -
    which is its own scroll container, fixed beside the page - comes back at the top. A reader who
    had scrolled down to reach a link found the menu had moved out from under the pointer, and had
    to scroll back before clicking anything else.

    The position is restored as early as the element exists rather than on DOMContentLoaded, so the
    menu is already where it was when the page first paints instead of jumping afterwards - which
    would be the same complaint with an extra step. Saved on scroll, and again on the way out, so a
    click that leaves immediately still records where the reader was.

    sessionStorage, not localStorage: this is where you were during this visit, and it should not
    outlive the tab. Every access is wrapped, because a browser set to refuse site data throws on
    the property itself rather than returning nothing.
  */
  const keepScrollerPlace = scroller => {
    const nav = scroller.find();
    const key = nav ? scroller.key() : null;
    if (!nav || !key || nav.dataset.keepsPlace === 'true') return Boolean(nav);
    nav.dataset.keepsPlace = 'true';
    try {
      const saved = Number(sessionStorage.getItem(key) || 0);
      if (saved > 0 && nav.scrollHeight > nav.clientHeight) nav.scrollTop = saved;
    } catch (_) {}
    // The entry being read, when the saved place leaves it out of sight.
    const current = scroller.current ? nav.querySelector(scroller.current) : null;
    if (current instanceof Element && nav.scrollHeight > nav.clientHeight) {
      const box = nav.getBoundingClientRect(), at = current.getBoundingClientRect();
      if (at.top < box.top || at.bottom > box.bottom) nav.scrollTop += at.top - box.top - (nav.clientHeight - at.height) / 2;
    }

    let pending = 0;
    const remember = () => {
      if (pending) return;
      pending = requestAnimationFrame(() => {
        pending = 0;
        try { sessionStorage.setItem(key, String(nav.scrollTop)); } catch (_) {}
      });
    };
    nav.addEventListener('scroll', remember, {passive:true});
    // pagehide rather than unload: unload is ignored by browsers that keep a page for the back
    // button, and this must be recorded on the way to the next page.
    window.addEventListener('pagehide', () => {
      try { sessionStorage.setItem(key, String(nav.scrollTop)); } catch (_) {}
    });

    return true;
  };
  // The navigation, whichever a theme draws, keeps its place; a project adds its own scrolling lists the same way.
  const navigation = {find: () => document.querySelector('.cl-sidebar, .cl-navigation, [data-theme-nav]'), key: () => 'cattophp:nav-scroll'};
  if (!keepScrollerPlace(navigation)) ready(() => keepScrollerPlace(navigation));

    /* Tree select: a hierarchy in a native popover, every branch rendered open so it all works
       without JavaScript. This shows each branch's disclosure button and, every time the list
       opens, closes every branch except those around the chosen option, so the reader starts from
       the top level and opens only what they need. A disclosure button only opens or closes its
       branch; choosing is the option itself. In field mode the options are radios: this shows the
       chosen path in the closed control and closes the list on a pointer choice, Enter or Space.
       Arrow keys move the choice and leave the list open. Run again on content swapped into the
       page (window.CattoTreeSelect.initialise). */
    // The root itself counts: a field swapped into the page is passed in as the root.
    const within = (root, selector) => [...(root instanceof Element && root.matches(selector) ? [root] : []), ...root.querySelectorAll(selector)];
    const initialiseTreeSelects = root => within(root, '.cl-ui-tree-select').forEach(select => {
      if (select.dataset.treeSelectReady === 'true') return;
      const panel = select.querySelector('[popover]'), value = select.querySelector('.cl-ui-tree-select-value');
      if (!panel) return;
      select.dataset.treeSelectReady = 'true';
      const expanders = [...panel.querySelectorAll('.cl-ui-tree-select-expand')];
      const setBranch = (button, open) => {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        const list = document.getElementById(button.getAttribute('aria-controls') || '');
        if (list) list.hidden = !open;
      };
      const showChosen = () => {
        expanders.forEach(button => setBranch(button, false));
        const chosen = panel.querySelector('input[type="radio"]:checked, [aria-current="true"]');
        for (let list = chosen ? chosen.closest('.cl-ui-tree-select-branch') : null; list; list = list.parentElement ? list.parentElement.closest('.cl-ui-tree-select-branch') : null) {
          const button = panel.querySelector(`[aria-controls="${CSS.escape(list.id)}"]`);
          if (button) setBranch(button, true);
        }
      };
      expanders.forEach(button => {
        button.hidden = false;
        button.addEventListener('click', event => { event.preventDefault(); setBranch(button, button.getAttribute('aria-expanded') !== 'true'); });
      });
      showChosen();
      panel.addEventListener('beforetoggle', event => { if (event.newState === 'open') showChosen(); });
      if (select.dataset.treeSelect !== 'field' || !value) return;
      const show = radio => { value.textContent = radio.dataset.treeSelectPath || ''; };
      const close = () => { if (panel.matches(':popover-open')) panel.hidePopover(); };
      panel.addEventListener('change', event => { if (event.target.matches('input[type="radio"]')) show(event.target); });
      panel.addEventListener('click', event => { if (event.detail > 0 && event.target.closest('label')) close(); });
      panel.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('input[type="radio"]')) {
          event.preventDefault();
          event.target.checked = true;
          show(event.target);
          close();
        }
      });
    });
  const treeSelectExtensions = [];
  // Run again on content swapped into the page; a project adds what its own fields beside a picker need (extend).
  window.CattoTreeSelect = {
    initialise: root => { initialiseTreeSelects(root); treeSelectExtensions.forEach(initialise => initialise(root)); },
    extend: initialise => { treeSelectExtensions.push(initialise); },
  };
  window.CattoPHP = {ready, within, keepScrollerPlace};


  ready(() => {
    let modalTrigger = null;
    const closeModal = modal => {
      if (!(modal instanceof Element)) return;
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true'); modal.setAttribute('aria-modal', 'false');
      modal.hidden = true;
      if (!document.querySelector('.cl-ui-modal.open')) {
        document.body.classList.remove('modal-open');
        const trigger = modalTrigger; modalTrigger = null;
        if (trigger && typeof trigger.focus === 'function') setTimeout(() => trigger.focus(), 10);
      }
    };
    const openModal = (id, trigger = null) => {
      const modal = document.getElementById(id || ''); if (!modal) return;
      document.querySelectorAll('.cl-ui-modal.open').forEach(closeModal);
      modalTrigger = trigger || document.activeElement;
      modal.hidden = false;
      modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); modal.setAttribute('aria-modal', 'true'); document.body.classList.add('modal-open');
      const focusTarget = modal.querySelector('input:not([type="hidden"]),select,textarea,button,a');
      if (focusTarget) focusTarget.focus();
    };
    const initialiseModals = () => document.querySelectorAll('.cl-ui-modal').forEach(modal => {
      if (!modal.classList.contains('open')) {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('aria-modal', 'false');
      }
    });
    initialiseModals();
    /* A window whose form was refused comes back open, holding what was typed (overlay.modal open: true).
       Without JavaScript every window is an ordinary section of the page, so it shows anyway. */
    const refusedWindow = document.querySelector('.cl-ui-modal[data-modal-open]');
    if (refusedWindow) openModal(refusedWindow.id);
    document.addEventListener('htmx:after:swap', () => initialiseModals());

    /* Delegated from document rather than bound per element, so a trigger or a close button that
       arrives in an htmx swap works without rebinding: a window whose results are replaced on every search
       would lose handlers bound to the markup they were attached to. */
    document.addEventListener('click', event => {
      if (!(event.target instanceof Element)) return;
      const opener = event.target.closest('[data-open-modal]');
      if (opener) { event.preventDefault(); openModal(opener.dataset.openModal, opener); return; }
      const closer = event.target.closest('[data-close-modal]');
      if (closer) { closeModal(closer.closest('.cl-ui-modal')); return; }
      if (event.target.classList.contains('cl-ui-modal')) { closeModal(event.target); return; }
      /* A link to a place on this page from inside a window, such as a link to a section of the page
         behind it, closes the window so the place can be seen; the link then scrolls to it as usual. */
      const inPage = event.target.closest('.cl-ui-modal.open a[href^="#"]');
      if (inPage && inPage.getAttribute('href').length > 1) closeModal(inPage.closest('.cl-ui-modal'));
    });

    /* Escape closes the top-most open modal. Without this a keyboard user who opens a window
       has no way out of it except a mouse. */
    document.addEventListener('keydown', event => {
      const open = document.querySelector('.cl-ui-modal.open');
      if (!open) return;
      /* An Escape something in the window has already used (a player closing its own menu or leaving its own full
         screen) leaves the window open; the next one closes it. */
      if (event.key === 'Escape' && event.defaultPrevented) return;
      if (event.key === 'Escape') { event.preventDefault(); closeModal(open); }
      if (event.key === 'Tab') {
        const controls = [...open.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled):not([type="hidden"]),select:not(:disabled),textarea:not(:disabled),[tabindex="0"]')]
          .filter(element => element.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (!first) { event.preventDefault(); return; }
        if (event.shiftKey && (document.activeElement === first || !open.contains(document.activeElement))) {
          event.preventDefault(); last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !open.contains(document.activeElement))) {
          event.preventDefault(); first.focus();
        }
      }
    });

    document.querySelectorAll('[data-tab-target]').forEach(button => button.addEventListener('click', () => {
      const group = button.dataset.tabGroup || 'default'; const target = button.dataset.tabTarget || '';
      document.querySelectorAll(`[data-tab-group="${CSS.escape(group)}"]`).forEach(item => item.classList.toggle('active', item === button));
      document.querySelectorAll(`[data-tab-panel-group="${CSS.escape(group)}"]`).forEach(panel => panel.classList.toggle('active', panel.dataset.tabPanel === target));
      if (button.dataset.tabHistory !== 'off') {
        const url = new URL(location.href); url.searchParams.set('tab', target); history.replaceState({}, '', url);
      }
    }));

    const dropdownSelector = 'details[data-cl-dropdown]';
    /* Queried at the moment it is needed rather than snapshotted at load. A row's actions menu
       arrives with the rows, and every page turn replaces them, so a list captured once holds
       elements that are no longer in the document and misses every one that is. */
    const closeDropdowns = except => document.querySelectorAll(dropdownSelector)
      .forEach(menu => { if (menu !== except) menu.open = false; });
    /* Delegated from document, for the same reason: `toggle` bubbles from a details element, so one
       listener covers the menus that exist now and the ones swapped in later. */
    document.addEventListener('toggle', event => {
      const menu = event.target instanceof Element ? event.target.closest(dropdownSelector) : null;
      if (menu && menu.open) closeDropdowns(menu);
    }, true);
    document.addEventListener('click', event => { const target = event.target instanceof Element ? event.target : null; if (!target || !target.closest(dropdownSelector)) closeDropdowns(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !event.defaultPrevented) { closeDropdowns(); document.querySelectorAll('.cl-ui-modal.open').forEach(closeModal); } });

    const navToggle = document.querySelector('[data-cl-nav-toggle]');
    const primaryNav = document.getElementById('cl-primary-navigation');
    if (navToggle && primaryNav) {
      document.body.dataset.clNavReady = '1';
      const setNavOpen = open => {
        document.body.dataset.clNavOpen = open ? '1' : '0';
        navToggle.setAttribute('aria-expanded', String(open));
      };
      setNavOpen(false);
      navToggle.addEventListener('click', () => setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true'));
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
          setNavOpen(false); navToggle.focus();
        }
      });
      primaryNav.addEventListener('click', event => {
        if (event.target instanceof Element && event.target.closest('a,button[type="submit"]')) setNavOpen(false);
      });
      window.matchMedia('(min-width:1121px)').addEventListener('change', () => setNavOpen(false));
    }

    /* A dismissed message takes its container with it when it was the last one.

       The message was being removed and the .cl-flash-stack around it was not, which is not an empty
       element with no consequences: a theme may give the stack a bottom margin, so a
       saved-settings notice that had faded out left that margin sitting above the page
       head, and the header never returned to the top of its container. Nothing on screen explained
       the gap, because the thing that reserved it was gone.

       :empty cannot express this in CSS - the whitespace text nodes between the messages survive
       removal, so the stack is never empty in the selector's sense - which is why the sweep is
       here, where the removal happens. */
    const dropEmptyStack = stack => {
      if (stack && stack.isConnected && stack.querySelector('[data-flash-message]') === null) stack.remove();
    };
    document.querySelectorAll('[data-flash-message]').forEach(flash => {
      const stack = flash.closest('.cl-flash-stack');
      const dismiss = () => { flash.classList.add('flash-hiding'); setTimeout(() => { flash.remove(); dropEmptyStack(stack); }, 180); };
      flash.querySelector('[data-flash-close]')?.addEventListener('click', dismiss);
      const type = flash.dataset.flashType || 'info'; if (type === 'success' || type === 'info') setTimeout(dismiss, type === 'success' ? 4500 : 6500);
    });
    /* One listener for every confirmation, so a control drawn into the page after it loaded (a list
       swapped in after a move, a form loaded into a modal) asks before it acts too. */
    document.addEventListener('click', event => {
      const element = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
      if (element && !confirm(element.dataset.confirm || 'Continue?')) event.preventDefault();
    });
    /* Cancel on a form opened in place in a disclosure (such as an Edit section in a tree):
       the form goes back to what was saved and
       closes where it is, with no page load. Without JavaScript the link loads the page again at the same row. */
    document.addEventListener('click', event => {
      const link = event.target instanceof Element ? event.target.closest('[data-close-disclosure]') : null;
      const details = link ? link.closest('details') : null;
      if (!details) return;
      event.preventDefault();
      link.closest('form')?.reset();
      details.open = false;
      details.querySelector(':scope > summary')?.focus();
    });
    /* Live dataset search needs nothing from this file. The debounce, the abort-in-flight and the
       loading indicator are declared on the input itself, and the submit button now lives inside a
       noscript element, so it is never in the document when scripting is available and is a real
       submit button when it is not.

       It used to be hidden here instead, and that was wrong twice over: this runs once at load, so
       every section arriving later by lazy load or htmx swap brought a button nothing hid, and the
       whole behaviour depended on a script file reaching the browser. Markup that is correct on
       arrival cannot drift out of step with a listener. */

    /* Current-page row filtering. This hides rows already rendered; it is not a search of the
       dataset, so it must never be attached to a paginated table - saying "3 of 25 rows" while
       the dataset holds thousands is a lie about what was searched. Surfaces with real
       server-side search mark themselves data-server-search and are skipped here, and themes are
       asked to stand down on the same marker. */
    document.querySelectorAll('[data-filter-input]').forEach(input => {
      if (input.closest('[data-server-search]')) return;
      input.addEventListener('input', () => { const q = input.value.toLowerCase().trim(); document.querySelectorAll(input.dataset.filterInput || '').forEach(row => { row.hidden = q !== '' && !row.textContent.toLowerCase().includes(q); }); });
    });

    initialiseTreeSelects(document);
    // A picker drawn into the page by htmx (a row edited in place) is made ready like the others.
    document.addEventListener('htmx:after:swap', () => initialiseTreeSelects(document));

    /* Feature cards that open a page (a form with data-choose-submits, such as a list of
       categories). A pointer choice, Space or Enter submits the form at once; arrow keys only move the choice.
       Delegated from the document, so cards swapped into the page work too. Without JavaScript the form's own
       button submits it. */
    let arrowChoice = false;
    const choosing = target => target instanceof HTMLInputElement && target.type === 'radio' && target.form && target.form.matches('[data-choose-submits]');
    document.addEventListener('keydown', event => {
      if (!choosing(event.target)) return;
      arrowChoice = event.key.startsWith('Arrow');
      if (event.key === 'Enter') { event.preventDefault(); event.target.checked = true; event.target.form.requestSubmit(); }
    });
    document.addEventListener('change', event => {
      if (!choosing(event.target)) return;
      if (arrowChoice) { arrowChoice = false; return; }
      event.target.form.requestSubmit();
    });

    /* Choices that open each one's own page, whose address carries its name (for search engines, never a number): a
       form marked data-named-pages goes straight to the chosen value's page, or to its own address for an empty choice. Without
       JavaScript the form asks its own address with the value, and the server sends the reader to the same page. */
    document.addEventListener('submit', event => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.matches('[data-named-pages]') || event.defaultPrevented) return;
      const from = event.submitter;
      const field = from instanceof HTMLButtonElement && from.name ? from : form.querySelector('input[type="radio"]:checked');
      if (!(field instanceof HTMLButtonElement || field instanceof HTMLInputElement)) return;
      event.preventDefault();
      window.location.assign(field.value ? form.dataset.namedPages + encodeURIComponent(field.value) : form.action);
    });

    /* A list keeps its place: a choice or a link inside the place marked data-keep-scroll loads the next page,
       and the place marked data-keep-scroll comes back where it was on the screen instead of the page opening
       at the top. Saved for the next page only, and only from this tab (sessionStorage). */
    const KEEP_SCROLL_KEY = 'cattophp:keep-scroll';
    const keepPlace = from => {
      const anchor = from.closest('[data-keep-scroll]');
      if (!anchor) return;
      try { sessionStorage.setItem(KEEP_SCROLL_KEY, JSON.stringify({top: anchor.getBoundingClientRect().top, at: Date.now()})); } catch (_) {}
    };
    // The button that sent the form counts: a search field and its button can sit in one place and belong to a
    // form drawn elsewhere (form="…").
    document.addEventListener('submit', event => { const from = event.submitter || event.target; if (from instanceof Element) keepPlace(from); }, true);
    document.addEventListener('click', event => {
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (link && !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey) keepPlace(link);
    }, true);
    try {
      const saved = JSON.parse(sessionStorage.getItem(KEEP_SCROLL_KEY) || 'null');
      sessionStorage.removeItem(KEEP_SCROLL_KEY);
      const anchor = document.querySelector('[data-keep-scroll]');
      if (saved && anchor && Date.now() - saved.at < 15000) {
        const place = () => window.scrollTo({top: Math.max(0, window.scrollY + anchor.getBoundingClientRect().top - saved.top), behavior: 'instant'});
        place();
        // Fonts and pictures can still move the page once loaded.
        window.addEventListener('load', place, {once: true});
      }
    } catch (_) {}

    /* Entity lookup selection.
       htmx fetches and swaps the results fragment; this only handles the click that commits a
       chosen result into the hidden field the surrounding form actually submits. Delegated
       from document so it works on fragments htmx inserts after page load. */
    document.addEventListener('click', event => {
      const choice = event.target instanceof Element ? event.target.closest('[data-lookup-target]') : null;
      if (choice) {
        const name = choice.getAttribute('data-lookup-target') || '';
        const value = document.getElementById(`${name}-value`);
        const selected = document.getElementById(`${name}-selected`);
        const search = document.getElementById(`${name}-search`);
        const results = document.getElementById(`${name}-results`);
        if (value instanceof HTMLInputElement) value.value = choice.getAttribute('data-lookup-id') || '';
        if (selected) selected.innerHTML = `<strong></strong>`, selected.firstChild.textContent = choice.getAttribute('data-lookup-label') || '';
        if (search instanceof HTMLInputElement) search.value = '';
        if (results) results.innerHTML = '';
        // A field that depends on the choice follows it.
        if (value instanceof HTMLInputElement) value.dispatchEvent(new Event('change', {bubbles: true}));
        return;
      }
      const clear = event.target instanceof Element ? event.target.closest('[data-lookup-clear]') : null;
      if (clear) {
        const name = clear.getAttribute('data-lookup-clear') || '';
        const value = document.getElementById(`${name}-value`);
        const selected = document.getElementById(`${name}-selected`);
        if (value instanceof HTMLInputElement) value.value = '';
        if (selected) selected.innerHTML = '<span class="muted">Nothing selected</span>';
        if (value instanceof HTMLInputElement) value.dispatchEvent(new Event('change', {bubbles: true}));
      }
    });

    /* Truncated table cells carry their full text as a tooltip.

       One row is one line, so anything wider than its column is cut off with an ellipsis. That is
       the right visual answer and the wrong informational one on its own: the reader can see that
       something was shortened and has no way to read it. A tooltip is the whole of the recovery.

       It is applied here rather than written into every template because it is a property of the
       rendered result, not of the markup: whether a cell overflows depends on the column width, the
       viewport and the content, and no template can know. Cells that fit get no tooltip, so
       hovering never produces a box repeating what is already on screen.

       A cell that already carries a title keeps it. Several tables set one deliberately - a person
       row shows the email address behind the name - and a deliberate tooltip always outranks this.

       Runs again after every htmx swap, because a page turn replaces the rows. */
    const applyCellTooltips = root => {
      const scope = root instanceof Element ? root : document;
      scope.querySelectorAll('.cl-ui-table td, .cl-ui-table th').forEach(cell => {
        if (cell.hasAttribute('title')) {
          if (cell.dataset.clAutoTitle !== '1') return;
        }
        const text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
        /* One pixel of slack: sub-pixel layout rounding reports a one-pixel overflow on cells that
           are not actually truncated, which would put a tooltip on most of the table. */
        if (text !== '' && cell.scrollWidth > cell.clientWidth + 1) {
          cell.setAttribute('title', text);
          cell.dataset.clAutoTitle = '1';
        } else if (cell.dataset.clAutoTitle === '1') {
          cell.removeAttribute('title');
          delete cell.dataset.clAutoTitle;
        }
      });
    };
    /* Columns sized to what they hold, so no column is cut short while another has room to spare.

       The server gives each column a share of the width as a percentage, which cannot know the rows of the page being
       shown. Here each table is laid out once at its natural width (data-column-fit="measuring": every column as wide as its
       widest entry on one line), and the space is then shared out: every column that needs no more than an equal share
       of what is left gets all it needs, and the columns that need more share the rest equally, so a cell is cut short
       only when the table truly cannot hold everything. When everything fits, the spare width goes to each column in
       proportion to what it holds. The widths are kept as percentages of the table, and worked out again when the
       window changes size, a page turns or a closed section opens. Without JavaScript the server's percentages stand.
       A table drawn with fit: false (data-column-fit="off") keeps its natural widths and scrolls sideways in its box, and one
       drawn with stack: true (data-stack="narrow") is cards on a narrow screen, fitted only when it is a table again. */
    // The one-line columns share space as above; a column whose text wraps (cell-text, such as a long title) loses
    // nothing by being narrower, so it gives way: it keeps a floor of 10rem and takes whatever the others leave.
    const waterFill = (needs, indexes, available, widths) => {
      let open = indexes.slice(), remaining = available;
      while (open.length) {
        const share = remaining / open.length;
        const fitting = open.filter(index => needs[index] <= share);
        if (!fitting.length) { open.forEach(index => { widths[index] = share; }); return 0; }
        fitting.forEach(index => { widths[index] = needs[index]; remaining -= needs[index]; });
        open = open.filter(index => needs[index] > share);
      }
      return remaining;
    };
    const shareWidth = (needs, wraps, available) => {
      const total = needs.reduce((sum, need) => sum + need, 0);
      if (total <= available) return needs.map(need => need + (available - total) * need / total);
      const widths = needs.slice();
      const wrapping = needs.map((_, index) => index).filter(index => wraps[index]);
      // On a phone two floors of 10rem are wider than the table, and would leave the one-line columns no width at all.
      const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) * 10;
      const floor = wrapping.length * rem > available / 2 ? Math.min(rem, available / needs.length) : rem;
      const lines = needs.map((_, index) => index).filter(index => !wraps[index]);
      const floors = wrapping.map(index => Math.min(needs[index], floor));
      const reserved = floors.reduce((sum, value) => sum + value, 0);
      const left = waterFill(needs, lines, Math.max(0, available - reserved), widths);
      const wanted = wrapping.reduce((sum, index, position) => sum + needs[index] - floors[position], 0);
      wrapping.forEach((index, position) => { widths[index] = floors[position] + (wanted > 0 ? left * (needs[index] - floors[position]) / wanted : 0); });
      return widths;
    };
    const fitTableColumns = root => {
      const scope = root instanceof Element ? root : document;
      within(scope, '.cl-ui-table').forEach(wrapper => {
        if (wrapper.dataset.columnFit === 'off') return;
        const table = wrapper.querySelector(':scope > table');
        const head = table && table.tHead && table.tHead.rows[0];
        if (!head || !wrapper.clientWidth) return;
        // A table drawn with stack: true is cards on a narrow screen (its rows are blocks): it has no columns to share out
        // until the screen is wide enough again, when it is fitted then.
        if (wrapper.dataset.stack && getComputedStyle(table).display !== 'table') return;
        const columns = [...head.cells];
        // A header or row cell spanning columns cannot be measured one column at a time.
        if (columns.some(cell => cell.colSpan > 1) || [...table.tBodies].some(body => [...body.rows].some(row => [...row.cells].some(cell => cell.colSpan > 1)))) return;
        wrapper.dataset.columnFit = 'measuring';
        const needs = columns.map(cell => cell.getClientRects().length ? Math.ceil(cell.getBoundingClientRect().width) : 0);
        delete wrapper.dataset.columnFit;
        const shown = needs.map((need, index) => need > 0 ? index : -1).filter(index => index >= 0);
        const available = table.clientWidth || wrapper.clientWidth;
        if (!shown.length || !available) return;
        const firstRow = table.tBodies[0] && table.tBodies[0].rows[0];
        const wraps = shown.map(index => !!(firstRow && firstRow.cells[index] && firstRow.cells[index].classList.contains('cell-text')));
        const widths = shareWidth(shown.map(index => needs[index]), wraps, available);
        shown.forEach((index, position) => { columns[index].style.width = (widths[position] / available * 100).toFixed(3) + '%'; });
        wrapper.dataset.columnFit = 'fitted';
      });
    };
    /* The pagination row fits its line (it must not scroll on a desktop screen). A list of a hundred
       pages or more has three-digit page numbers, and a theme with a narrow content column may not hold the whole
       numbered window. Where the row is too wide, the numbered pages farthest from the
       current one are hidden, one at a time, until it fits: never the first, the last or the current page, and only in a
       window that already has an ellipsis, so a hidden page always sits in a gap the reader can see. They come back
       when there is room again. Without JavaScript the row scrolls inside itself. */
    const fitPagination = root => {
      const scope = root instanceof Element ? root : document;
      within(scope, 'nav.pagination-row').forEach(row => {
        const items = [...row.querySelectorAll('.pagination-pages-list > li')];
        items.filter(item => 'pagerTrimmed' in item.dataset).forEach(item => { item.hidden = false; delete item.dataset.pagerTrimmed; });
        if (!row.clientWidth || row.scrollWidth <= row.clientWidth + 1 || !row.querySelector('.pagination-gap')) return;
        const current = items.findIndex(item => item.querySelector('[aria-current="page"]'));
        const trimmable = items.map((item, index) => index)
          .filter(index => index > 0 && index < items.length - 1 && index !== current && !items[index].querySelector('.pagination-gap'))
          .sort((a, b) => Math.abs(b - current) - Math.abs(a - current));
        for (const index of trimmable) {
          if (row.scrollWidth <= row.clientWidth + 1) break;
          items[index].hidden = true;
          items[index].dataset.pagerTrimmed = '';
        }
      });
    };
    /* A small stat card's money amount stays on one line inside its card (a large amount must not run out of its
       card). Core draws it at 0.88 of the theme's own size; an amount still too wide for its card, a very
       large one or a card on a narrow screen, is drawn smaller until it fits.
       Each amount is fitted again whenever it or its card changes size, whatever the cause: a web font arriving at any
       moment, or the card narrowing with the window (a late font once left an amount past its card, though the
       page refits on every font and load event). The amount is an inline-block so
       its width can be watched. The fit runs in the next frame, never inside the observer's own delivery, so resizing
       the amount cannot loop. The amount is never animated (Core's CSS): a theme's transition made a measurement read
       the size it was changing from, so a too-wide amount was sometimes judged to fit. Its width is
       measured again after each shrink, because text does not always narrow exactly in step with its size. */
    const fitFigure = amount => {
      amount.style.fontSize = '';
      const room = amount.parentElement.clientWidth;
      for (let pass = 0; pass < 4; pass++) {
        const width = amount.getBoundingClientRect().width;
        if (!room || width <= room) break;
        amount.style.fontSize = (parseFloat(getComputedStyle(amount).fontSize) * room / width * (pass ? 0.95 : 0.98)).toFixed(2) + 'px';
      }
    };
    const figureWatch = 'ResizeObserver' in window ? new ResizeObserver(entries => requestAnimationFrame(() => {
      new Set(entries.map(entry => entry.target.matches('.cl-ui-stat-value') ? entry.target.querySelector(':scope > span') : entry.target))
        .forEach(amount => { if (amount && amount.isConnected) fitFigure(amount); });
    })) : null;
    const fitFigures = root => {
      const scope = root instanceof Element ? root : document;
      within(scope, '.cl-ui-stat-card--small .cl-ui-stat-value > span').forEach(amount => {
        fitFigure(amount);
        if (figureWatch && !('figureWatched' in amount.dataset)) {
          amount.dataset.figureWatched = '';
          figureWatch.observe(amount);
          figureWatch.observe(amount.parentElement);
        }
      });
    };
    const fitAndExplain = root => { fitTableColumns(root); applyCellTooltips(root); fitPagination(root); fitFigures(root); };
    fitAndExplain(document);
    // Again once the theme's web fonts have arrived: a figure or a column measured in the fallback font can change width.
    // A font that starts loading later (a style the page reaches only after a theme's palette is applied) is fitted when
    // it arrives, and everything once more when the page has loaded (an amount measured before its font was once seen
    // past its card).
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => fitAndExplain(document));
    if (document.fonts && document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', () => fitAndExplain(document));
    window.addEventListener('load', () => fitAndExplain(document));
    // htmx 4's event name; the swapped element can be gone (an outerHTML swap), so the page is looked at again.
    document.addEventListener('htmx:after:swap', () => fitAndExplain(document));
    // A table inside a section that was closed had no width to share out until it opened.
    document.addEventListener('toggle', event => { if (event.target instanceof HTMLDetailsElement && event.target.open) fitAndExplain(event.target); }, true);
    /* And when the viewport changes, because a column that fitted at one width may not at another. */
    let tooltipResize = 0;
    window.addEventListener('resize', () => {
      clearTimeout(tooltipResize);
      tooltipResize = setTimeout(() => fitAndExplain(document), 200);
    });

  });
})();
/*
  A table row's tooltip. On a phone a table shows only its essential columns, in
  7pt, and cuts long values short, so a row may not say everything it holds. Pointing at a row,
  tapping it on a touch screen or moving focus into it shows the whole row as "Label: value" lines.
  The text goes into the tooltip element the table component renders empty; nothing here builds
  markup. Rows where nothing is hidden or cut short show no tooltip, and the action cell is left out
  because its controls are already on screen. An open row menu is never covered: the tooltip hides
  while the pointer is on the actions and sits below the menu panel's layer.
*/
(function () {
    'use strict';
    var tip = null, current = null;
    var ROW = '.cl-ui-table > table > tbody > tr';
    var INTERACTIVE = 'a, button, summary, input, select, textarea, label, details, .cell-actions';
    function tipElement() {
        if (tip && tip.isConnected) return tip;
        tip = document.querySelector('.cl-ui-table-tip');
        // Fixed to the window, so it is moved out of any card a theme gives a transform or filter.
        if (tip && tip.parentElement !== document.body) document.body.appendChild(tip);
        return tip;
    }
    function clean(text) {
        return (text || '').replace(/[↑-↕▲▼]/g, '').replace(/\s+/g, ' ').trim();
    }
    function cutShort(cell) {
        if (cell.scrollWidth > cell.clientWidth + 1) return true;
        return Array.prototype.some.call(cell.querySelectorAll('*'), function (element) {
            return element.scrollWidth > element.clientWidth + 1 && getComputedStyle(element).overflowX !== 'visible';
        });
    }
    function summary(row) {
        var table = row.closest('table');
        var heads = table ? table.querySelectorAll(':scope > thead > tr:first-child > th') : [];
        var needed = false, lines = [];
        Array.prototype.forEach.call(row.children, function (cell, index) {
            if (cell.classList.contains('cell-actions')) return;
            var text = clean(cell.textContent);
            if (!text) return;
            if (getComputedStyle(cell).display === 'none' || cutShort(cell)) needed = true;
            var label = heads[index] ? clean(heads[index].textContent) : '';
            lines.push(label ? label + ': ' + text : text);
        });
        // One straight line along the row, not a box of stacked lines.
        return needed ? lines.join('  ·  ') : '';
    }
    function hide() {
        if (tip) tip.hidden = true;
        current = null;
    }
    function show(row) {
        var element = tipElement();
        var text = element ? summary(row) : '';
        if (!text) { hide(); return; }
        element.textContent = text;
        element.hidden = false;
        current = row;
        var rect = row.getBoundingClientRect();
        // As wide as the row, inside the window, so the line runs along it.
        var left = Math.max(8, rect.left);
        element.style.width = Math.max(120, Math.min(rect.right, window.innerWidth - 8) - left) + 'px';
        var box = element.getBoundingClientRect();
        var top = rect.bottom + 6;
        if (top + box.height > window.innerHeight - 8) top = Math.max(8, rect.top - box.height - 6);
        element.style.left = left + 'px';
        element.style.top = top + 'px';
    }
    document.addEventListener('pointerover', function (event) {
        if (event.pointerType === 'touch') return;
        var row = event.target.closest && event.target.closest(ROW);
        if (!row || event.target.closest('.cell-actions')) { hide(); return; }
        if (row !== current) show(row);
    });
    document.addEventListener('pointerup', function (event) {
        if (event.pointerType !== 'touch') return;
        var row = event.target.closest && event.target.closest(ROW);
        if (!row || event.target.closest(INTERACTIVE)) { hide(); return; }
        if (row === current) hide(); else show(row);
    });
    document.addEventListener('focusin', function (event) {
        var row = event.target.closest && event.target.closest(ROW);
        if (row && !event.target.closest('.cell-actions')) show(row); else hide();
    });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') hide(); });
    document.addEventListener('toggle', function (event) { if (event.target.matches && event.target.matches('.cl-ui-row-menu') && event.target.open) hide(); }, true);
    window.addEventListener('scroll', hide, {passive: true});
    window.addEventListener('resize', hide);
})();

/* A link to a closed disclosure opens it (/help#answer opens that answer). */
(function () {
    function openTarget() {
        var id = decodeURIComponent((location.hash || '').slice(1));
        var target = id ? document.getElementById(id) : null;
        if (target && target.tagName === 'DETAILS' && !target.open) target.open = true;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', openTarget, {once: true}); else openTarget();
    window.addEventListener('hashchange', openTarget);
    /* A printed page shows every section (the legal pages say "You can print this page"): the accordions closed
       for printing close again afterwards. */
    var openedForPrint = [];
    window.addEventListener('beforeprint', function () {
        openedForPrint = Array.prototype.filter.call(document.querySelectorAll('details.cl-ui-accordion-section:not([open])'), function (section) { section.open = true; return true; });
    });
    window.addEventListener('afterprint', function () { openedForPrint.forEach(function (section) { section.open = false; }); openedForPrint = []; });
})();

/* A colour typed as a hex code, never a colour picker: the swatch beside the field follows the code as it is typed, and
   shows nothing while the code is not yet six hex digits. Without this script the swatch shows the saved colour. */
(function () {
    function paint(input) {
        var swatch = input.parentElement ? input.parentElement.querySelector('.cl-hex-colour-swatch rect') : null;
        if (!swatch) return;
        var code = input.value.trim();
        if (/^[0-9a-f]{6}$/i.test(code)) code = '#' + code;
        var fine = /^#[0-9a-f]{6}$/i.test(code);
        swatch.setAttribute('fill', fine ? code : 'none');
        input.setAttribute('aria-invalid', fine || code === '' ? 'false' : 'true');
    }
    document.addEventListener('input', function (event) {
        if (event.target instanceof HTMLInputElement && event.target.matches('input[data-hex-colour]')) paint(event.target);
    });
    function initialise(root) { (root || document).querySelectorAll('input[data-hex-colour]').forEach(paint); }
    document.addEventListener('htmx:after:swap', function () { initialise(document); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initialise(document); }, {once: true}); else initialise(document);
})();

/* A choice that fills a field (such as addresses matching what is typed): pressing it puts its value in the field it names and closes the list of choices. */
(function () {
    document.addEventListener('click', function (event) {
        var choice = event.target instanceof Element ? event.target.closest('[data-fill-into]') : null;
        if (!choice || choice.disabled) return;
        var field = document.getElementById(choice.getAttribute('data-fill-into'));
        if (!field) return;
        field.value = choice.getAttribute('data-fill-value') || '';
        field.focus();
        var list = choice.closest('[role="region"]');
        if (list) list.innerHTML = '';
    });
})();

/* A page that shows live data refreshes itself in place (@CattoPHP/page/live-status.html.twig).
   It holds back while the reader is busy, so a refresh never takes what they are looking at: typing, text selected, a menu
   open, something they opened to read (data-live-hold, which the line names with its live_held words), a later page whose
   rows would otherwise move, or a tab out of sight. Pause holds it for this tab. */
(function () {
    var KEY = 'cattophp:live-paused';
    var paused = false;
    try { paused = sessionStorage.getItem(KEY) === '1'; } catch (_) {}
    function onALaterPage() {
        var later = false;
        new URLSearchParams(location.search).forEach(function (value, key) { if (key.slice(-5) === '_page' && Number(value) > 1) later = true; });
        return later;
    }
    function typing() {
        var focused = document.activeElement;
        // A drop-down keeps the focus after a choice, so it does not count, or the page would stop refreshing until the next click.
        return !!focused && focused.matches('textarea, input:not([type=radio]):not([type=checkbox]):not([type=hidden]):not([type=submit]):not([type=button])');
    }
    function selecting() {
        var selection = window.getSelection ? window.getSelection() : null;
        return !!selection && !selection.isCollapsed && selection.toString().trim() !== '';
    }
    function holding() { return !!document.querySelector('[data-live-hold]'); }
    window.cattoLive = {
        mayRefresh: function () {
            return !paused && document.visibilityState === 'visible' && !typing() && !selecting() && !onALaterPage() && !holding()
                && !document.querySelector('.cl-main details[open], dialog[open], [aria-modal="true"]:not([hidden])');
        }
    };
    function show(selector, shown) { document.querySelectorAll(selector).forEach(function (element) { element.hidden = !shown; }); }
    function draw() {
        show('[data-live-status]', true);
        show('[data-live-running]', !paused && !holding());
        show('[data-live-held]', !paused && holding());
        show('[data-live-paused]', paused);
        show('#live-pause', !paused);
        show('#live-resume', paused);
    }
    function stamp() {
        var words = 'Last updated ' + new Date().toLocaleTimeString('en-ZA', {hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false}) + '.';
        document.querySelectorAll('[data-live-when]').forEach(function (element) { element.textContent = words; });
    }
    function hold(value) {
        paused = value;
        try { if (paused) sessionStorage.setItem(KEY, '1'); else sessionStorage.removeItem(KEY); } catch (_) {}
        draw();
    }
    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('#live-pause')) { hold(true); var resume = document.getElementById('live-resume'); if (resume) resume.focus(); }
        else if (event.target.closest('#live-resume')) { hold(false); var pause = document.getElementById('live-pause'); if (pause) pause.focus(); }
    });
    document.addEventListener('htmx:after:swap', function () { draw(); stamp(); });
    document.addEventListener('htmx:after:settle', draw);
    // Back and Forward bring back a page that may hold something open, or no longer does.
    window.addEventListener('popstate', function () { setTimeout(draw, 0); });
    function start() { draw(); stamp(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
})();

/* Forms that act on a change, found by the data attributes the page carries, since the content policy runs no script
   written into a page. Each is a convenience: without JavaScript each form still has its own button. */
(function () {
    'use strict';

    /* A drop-down or date that sends its form when it changes (a period, a month, rows per page, a filter), unless the
       choice is one that needs more first (data-submit-unless: "Dates" waits for the two dates). */
    document.addEventListener('change', function (event) {
        var field = event.target instanceof Element ? event.target.closest('[data-submit-on-change]') : null;
        if (!field || !field.form) return;
        if (field.hasAttribute('data-submit-unless') && field.value === field.getAttribute('data-submit-unless')) return;
        if (field.form.requestSubmit) field.form.requestSubmit(); else field.form.submit();
    });

    /* A field that sets another when it changes (a date typed makes a period drop-down say "Dates"). */
    document.addEventListener('change', function (event) {
        var field = event.target instanceof Element ? event.target.closest('[data-sets-field]') : null;
        var other = field && field.form ? field.form.elements.namedItem(field.getAttribute('data-sets-field')) : null;
        if (other && 'value' in other) other.value = field.getAttribute('data-sets-value') || '';
    });

    /* A form that asks before it is sent (such as Finish now). Caught before anything else hears the form. */
    document.addEventListener('submit', function (event) {
        var form = event.target instanceof HTMLFormElement && event.target.hasAttribute('data-confirm-submit') ? event.target : null;
        if (form && !window.confirm(form.getAttribute('data-confirm-submit') || 'Continue?')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    /* A page that shows live data asks for itself again every data-live-seconds (@CattoPHP/page/live-status.html.twig),
       when window.cattoLive (above) says the reader is not busy. The count was htmx's own, but its condition is code htmx
       compiles, which the content policy does not allow. */
    var due = new WeakMap();
    setInterval(function () {
        var status = document.querySelector('[data-live-seconds]');
        if (!status) return;
        var now = Date.now();
        var seconds = Math.max(5, Number(status.getAttribute('data-live-seconds')) || 30);
        if (!due.has(status)) { due.set(status, now + seconds * 1000); return; }
        if (now < due.get(status)) return;
        due.set(status, now + seconds * 1000);
        if (window.cattoLive && window.cattoLive.mayRefresh()) status.dispatchEvent(new CustomEvent('catto-live-refresh', {bubbles: true}));
    }, 1000);
})();
