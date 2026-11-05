<script setup lang="ts">
import { computed, ref } from 'vue'
import AppShell from './components/AppShell.vue'
import LoginPage from './pages/LoginPage.vue'
import DashboardPage from './pages/DashboardPage.vue'

const currentPath = ref(window.location.pathname)
const isLogin = computed(() => currentPath.value === '/login' || currentPath.value === '/')
function navigate(path: string) {
  history.pushState({}, '', path)
  currentPath.value = path
}
window.addEventListener('popstate', () => { currentPath.value = window.location.pathname })
</script>

<template>
  <LoginPage v-if="isLogin" @navigate="navigate" />
  <AppShell v-else :path="currentPath" @navigate="navigate">
    <DashboardPage />
  </AppShell>
</template>
