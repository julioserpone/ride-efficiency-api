<script setup lang="ts">
import type { PropType } from 'vue';

defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], required: true },
  icon: { type: String, required: false },
  trend: { type: String as PropType<'up' | 'down' | null>, default: null },
  subtitle: { type: String, default: '' },
  variant: { type: String as PropType<'primary' | 'success' | 'warning' | 'danger'>, default: 'primary' },
});
</script>

<template>
  <div class="rounded-3xl border border-border bg-card p-6 shadow-sm">
    <div class="flex items-start gap-4">
      <div
        class="grid h-12 w-12 place-items-center rounded-2xl bg-muted text-muted-foreground"
      >
        <span class="text-lg">{{ icon }}</span>
      </div>
      <div class="space-y-1">
        <p class="text-sm font-medium text-muted-foreground">{{ title }}</p>
        <p class="text-2xl font-semibold text-foreground">{{ value }}</p>
      </div>
    </div>

    <div class="mt-4 flex items-center justify-between gap-4 text-sm text-muted-foreground">
      <p>{{ subtitle }}</p>
      <span
        v-if="trend"
        :class="{
          'text-emerald-500': trend === 'up',
          'text-rose-500': trend === 'down',
        }"
      >
        {{ trend === 'up' ? '▲ Mejorando' : '▼ A la baja' }}
      </span>
    </div>
  </div>
</template>
