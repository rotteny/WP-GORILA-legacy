<template>
  <div class="topbar">
    <a href="/" class="logo">
      <span class="logo-mark">🦍</span>
      <span class="logo-name">WP<small>·</small>Gorila</span>
    </a>
    <div class="spacer"></div>
  </div>
</template>

<script>
export default {
  name: 'Topbar',
};
</script>

<style scoped>
.topbar {
  height: 64px;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 0 28px;
  background: var(--panel);
  border-bottom: 1px solid var(--line);
  position: relative;
  z-index: 5;
  flex-shrink: 0;
}

.logo {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 800;
  font-size: 18px;
  letter-spacing: -0.02em;
  text-decoration: none;
  color: inherit;
}

.logo-name small {
  margin: 0 4px;
  color: var(--brand);
  font-weight: 800;
}

.logo-mark {
  width: 34px;
  height: 34px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  font-size: 26px;
}

.spacer {
  flex: 1;
}
</style>
