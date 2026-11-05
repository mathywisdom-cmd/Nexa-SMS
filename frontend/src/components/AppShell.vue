<script setup lang="ts">
import { ref } from 'vue'
defineProps<{ path: string }>()
defineEmits<{ navigate: [path: string] }>()
const sidebarOpen = ref(false)
const menu = [
  { label: 'Dashboard', icon: 'icon-home4', path: '/dashboard' },
  { label: 'Academics', icon: 'icon-graduation2', path: '/academics' },
  { label: 'Administrative', icon: 'icon-office', path: '/administrative' },
  { label: 'Students', icon: 'icon-users', path: '/students' },
  { label: 'Users', icon: 'icon-users4', path: '/users' },
  { label: 'Classes', icon: 'icon-windows2', path: '/classes' },
  { label: 'Dormitories', icon: 'icon-home9', path: '/dormitories' },
  { label: 'Sections', icon: 'icon-fence', path: '/sections' },
  { label: 'Subjects', icon: 'icon-pin', path: '/subjects' },
  { label: 'Exams', icon: 'icon-books', path: '/exams' },
  { label: 'School Settings', icon: 'icon-cog3', path: '/settings' },
]
</script>

<template>
  <header class="navbar navbar-expand-md navbar-dark app-topbar">
    <a class="brand" href="/dashboard" @click.prevent="$emit('navigate', '/dashboard')">Your School</a>
    <button class="navbar-toggler sidebar-toggle" aria-label="Toggle navigation" @click="sidebarOpen = !sidebarOpen"><i class="icon-paragraph-justify3" /></button>
    <div class="ml-auto d-flex align-items-center user-menu"><img class="rounded-circle avatar" src="/global_assets/images/user.png" alt="" /><span>School Administrator</span><i class="icon-arrow-down5 ml-2" /></div>
  </header>
  <div class="page-content" :class="{ 'sidebar-open': sidebarOpen }">
    <aside class="sidebar sidebar-light sidebar-expand-md">
      <div class="sidebar-user"><img src="/global_assets/images/user.png" class="rounded-circle avatar-lg" alt="" /><div><strong>Local Smoke Admin A</strong><small>Admin</small></div><i class="icon-cog3 ml-auto" /></div>
      <nav class="navigation"><a v-for="item in menu" :key="item.path" :href="item.path" class="nav-link" :class="{ active: path === item.path || (path === '/' && item.path === '/dashboard') }" @click.prevent="$emit('navigate', item.path)"><i :class="item.icon" /><span>{{ item.label }}</span><i v-if="['Academics','Administrative','Students','Exams'].includes(item.label)" class="icon-arrow-left5 ml-auto" /></a></nav>
    </aside>
    <main class="content-wrapper">
      <div class="page-heading"><div><i class="icon-plus-circle2 text-primary mr-2" /><strong>My Dashboard</strong></div><small>Current Session: 2026-2027</small></div>
      <section class="content"><slot /></section>
    </main>
  </div>
</template>
