<x-layouts.app :title="__('Edit customer')" section="customers">
    <x-page-head :title="$customer->name" :subtitle="__('Edit customer')" />

    <div class="narrow">
        <section class="card card-pad">
            @include('app.customers._form', ['action' => route('app.customers.update', $customer), 'method' => 'PUT'])
        </section>
    </div>
</x-layouts.app>
