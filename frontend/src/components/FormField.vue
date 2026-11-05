<script setup lang="ts">
defineProps<{ label: string; name: string; type?: string; placeholder?: string; required?: boolean }>()
</script>
<template>
  <div class="form-field"><label :for="name">{{ label }}<span v-if="required" class="text-danger"> *</span></label><input :id="name" class="form-control" :name="name" :type="type || 'text'" :placeholder="placeholder" :required="required" /></div>
</template>
