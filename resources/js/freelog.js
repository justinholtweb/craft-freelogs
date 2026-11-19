// Freelog's log viewer: expandable rows, filter-on-change, the live tail and the clear
// confirmation.
//
// Craft 6 loads plugin CP scripts on every control-panel page, in <head>, before the Inertia app
// renders the screen — so everything here is delegated from `document` and does nothing unless a
// Freelog screen is showing. No jQuery and no `Craft.*` helpers: neither exists in Craft 6's CP.
;(() => {
  if (window.__freelog) return
  window.__freelog = true

  const POLL_MS = 2000
  let tail = null

  const root = (el) => el.closest('.freelog')

  // Expand and collapse an entry: click, or Enter/Space on the focused row.
  function toggleRow(row) {
    const expanded = row.classList.toggle('expanded')
    row.setAttribute('aria-expanded', expanded ? 'true' : 'false')
  }

  document.addEventListener('click', (event) => {
    const target = event.target
    if (!(target instanceof Element)) return

    const toggle = target.closest('#freelog-tail-toggle')
    if (toggle) {
      tail ? stopTail() : startTail(toggle)
      return
    }

    const row = target.closest('.freelog-entries tr[data-expandable]')
    // Let a text selection inside an expanded message stand.
    if (row && !String(window.getSelection?.() ?? '')) toggleRow(row)
  })

  document.addEventListener('keydown', (event) => {
    const target = event.target
    if (!(target instanceof Element) || (event.key !== 'Enter' && event.key !== ' ')) return
    const row = target.closest('.freelog-entries tr[data-expandable]')
    if (row && row === target) {
      event.preventDefault()
      toggleRow(row)
    }
  })

  // Pick a file or a level and the view follows.
  document.addEventListener('change', (event) => {
    const target = event.target
    if (target instanceof HTMLSelectElement && target.matches('.freelog [data-freelog-autosubmit]') && target.form) {
      target.form.submit()
    }
  })

  // Clearing erases the record of what happened: ask first. In the capture phase, so a cancel
  // stops the submit before anything else on the page acts on it.
  document.addEventListener('submit', (event) => {
    const form = event.target
    if (!(form instanceof HTMLFormElement) || !form.matches('.freelog-clear-form')) return
    const message = root(form)?.dataset.freelogConfirm || 'Clear this log file?'
    if (!window.confirm(message)) {
      event.preventDefault()
      event.stopImmediatePropagation()
    }
  }, true)

  function startTail(button) {
    const view = root(button)
    const panel = view?.querySelector('#freelog-tail-content')
    const url = panel?.dataset.tailUrl
    if (!panel || !url) return

    tail = { button, panel, url, view, timer: setInterval(() => fetchTail(), POLL_MS) }

    // The panel starts hidden; the tail polls into it once it shows.
    panel.hidden = false
    button.setAttribute('aria-expanded', 'true')
    button.textContent = view.dataset.freelogStop || 'Stop auto-refresh'
    view.querySelector('#freelog-tail-status')?.classList.add('active')

    fetchTail()
  }

  function stopTail() {
    if (!tail) return
    const { button, view, timer } = tail
    clearInterval(timer)
    tail = null

    button.setAttribute('aria-expanded', 'false')
    button.textContent = view.dataset.freelogStart || 'Start auto-refresh'
    view.querySelector('#freelog-tail-status')?.classList.remove('active')
  }

  async function fetchTail() {
    const current = tail
    if (!current) return

    // The screen went away under us (an Inertia visit): stop polling a panel nobody sees.
    if (!current.panel.isConnected) {
      stopTail()
      return
    }

    try {
      const response = await fetch(current.url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (!response.ok) return
      const data = await response.json()
      if (tail === current && typeof data.content === 'string') {
        current.panel.textContent = data.content
        current.panel.scrollTop = current.panel.scrollHeight
      }
    } catch {
      // A dropped poll is retried on the next tick.
    }
  }

  document.addEventListener('inertia:navigate', stopTail)
})()
