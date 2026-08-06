<script setup lang="ts">
import { computed } from 'vue';
import type { PropType } from 'vue';

const props = defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], required: true },
  icon: { type: String, required: false },
  trend: { type: String as PropType<'up' | 'down' | null>, default: null },
  subtitle: { type: String, default: '' },
  variant: { type: String as PropType<'primary' | 'success' | 'warning' | 'danger'>, default: 'primary' },
});

const iconClasses = computed(() => {
  return {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-emerald-100 text-emerald-700',
    warning: 'bg-amber-100 text-amber-700',
    danger: 'bg-rose-100 text-rose-700',
  }[props.variant] ?? 'bg-muted text-muted-foreground';
});
</script>

<template>
  <div class="rounded-[2rem] border border-border/70 bg-card/95 p-6 shadow-soft transition hover:-translate-y-0.5 hover:shadow-[0_24px_60px_-30px_rgba(15,23,42,0.28)]">
    <div class="flex items-start gap-4">
      <div :class="['grid h-14 w-14 place-items-center rounded-3xl text-xl', iconClasses]">
        <span>{{ icon }}</span>
      </div>
      <div class="space-y-1">
        <p class="text-sm font-medium uppercase tracking-[0.18em] text-muted-foreground">{{ title }}</p>
        <p class="text-3xl font-semibold text-foreground">{{ value }}</p>
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
