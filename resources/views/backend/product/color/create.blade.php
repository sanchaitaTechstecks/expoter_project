@extends('backend.layouts.app')
@section('content')

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0 h6">{{translate('Color Information')}}</h5>
            </div>
            <div class="card-body">
                <form action="{{ route('colors.store') }}" method="POST" id="aizSubmitForm">
                    @csrf
                    <div class="form-group mb-3">
                        <label for="name">{{ translate('Name') }}</label>
                        <input type="text" placeholder="{{ translate('Name') }}" id="name" name="name"
                            class="form-control" value="{{ old('name') }}" required>
                    </div>
                    <div class="form-group mb-3">
                        <label for="name">{{ translate('Color Code') }}</label>
                        <input type="text" placeholder="{{ translate('Color Code') }}" id="code" name="code"
                            class="form-control" value="{{ old('code') }}" required>
                    </div>
                    <div class="form-group mb-3 text-right">
                        <button type="submit" class="btn btn-primary">{{ translate('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection



