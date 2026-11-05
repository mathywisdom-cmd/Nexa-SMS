<script setup lang="ts">
import BaseButton from '../components/BaseButton.vue'
defineEmits<{ navigate: [path: string] }>()
</script>
<template>
  <div class="login-page">
    <header class="navbar navbar-expand-md navbar-dark login-header">
      <a class="brand" href="/login">NexaSMS by Mathias</a>
      <nav class="ml-auto login-icons"><a href="/login" aria-label="Home"><i class="icon-home" /></a><a href="/login" aria-label="Account"><i class="icon-user-tie" /></a><a href="/login" aria-label="Settings"><i class="icon-cog3" /></a></nav>
    </header>
    <main class="login-cover">
      <form class="login-form" @submit.prevent>
        <div class="card mb-0"><div class="card-body">
          <div class="text-center mb-3"><i class="icon-people login-icon" /><h5 class="mb-0">Login to your account</h5><span class="d-block text-muted">Your credentials</span></div>
          <div class="form-group"><input class="form-control" type="text" name="identity" placeholder="Login ID or Email" autocomplete="username" /></div>
          <div class="form-group"><input class="form-control" type="password" name="password" placeholder="Password" autocomplete="current-password" /></div>
          <div class="form-group d-flex align-items-center"><label class="form-check-label"><input class="mr-2" type="checkbox" />Remember</label><a class="ml-auto" href="#forgot">Forgot password?</a></div>
          <div class="form-group"><BaseButton class="btn-block" kind="primary">Sign in <i class="icon-circle-right2 ml-2" /></BaseButton></div>
          <p class="preview-note">Preview only. Authentication will be connected in a later stage.</p>
        </div></div>
      </form>
    </main>
    <footer class="navbar navbar-expand-lg navbar-light login-footer"><span>© 2026 <a href="/login">NexaSMS by Mathias.</a> All rights reserved.</span><nav class="ml-auto"><a href="#privacy"><i class="icon-lifebuoy mr-2" />Privacy Policy</a><a href="#terms"><i class="icon-file-text2 mr-2" />Terms &amp; Conditions</a></nav></footer>
  </div>
</template>
