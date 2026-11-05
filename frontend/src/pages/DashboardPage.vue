<script setup lang="ts">
import EmptyState from '../components/EmptyState.vue'
const cards = [
  { label: 'TOTAL STUDENTS', color: 'blue', icon: 'icon-users4' },
  { label: 'TOTAL TEACHERS', color: 'red', icon: 'icon-users2' },
  { label: 'TOTAL ADMINISTRATORS', color: 'green', icon: 'icon-pointer' },
  { label: 'TOTAL PARENTS', color: 'indigo', icon: 'icon-user' },
]
const metrics = ['Classes', 'Present today', 'Absent today', 'Late today', 'Attendance rate', 'Fee collection today', 'Outstanding fees', 'Exams this session']
</script>
<template>
  <div class="row">
    <div v-for="card in cards" :key="card.label" class="col-sm-6 col-xl-3"><div class="card card-body stat-card" :class="`bg-${card.color}`"><div><h3 class="mb-1">—</h3><span>{{ card.label }}</span></div><i :class="card.icon" /></div></div>
  </div>
  <div class="row">
    <div v-for="metric in metrics" :key="metric" class="col-sm-6 col-xl-3"><div class="card card-body metric-card"><span>{{ metric }}</span><h3>—</h3></div></div>
  </div>
  <div class="card"><div class="card-header"><h5 class="card-title">Recent payments</h5></div><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Student</th><th>Fee</th><th>Amount paid</th></tr></thead></table><EmptyState title="No payments recorded" message="Payments will appear here when connected to the school data." /></div></div>
  <div class="card"><div class="card-header"><h5 class="card-title">Recent school activity</h5></div><EmptyState title="No recent activity" message="School activity will appear here when connected to the school data." /></div>
  <div class="card"><div class="card-header"><h5 class="card-title">School Events Calendar</h5></div><div class="card-body calendar-placeholder"><span>School events calendar</span></div></div>
</template>
