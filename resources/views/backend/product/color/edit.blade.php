@extends('backend.layouts.app')

@section('content')



<div class="col-lg-8 mx-auto">
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0 h6">{{translate('Color Information')}}</h5>
        </div>
        <div class="card-body p-0">
            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form class="p-4" action="{{ route('colors.update', $color->id) }}" method="POST" id="aizSubmitForm">
                <input name="_method" type="hidden" value="POST">
                @csrf
                <div class="form-group mb-3">
                    <label class="col-from-label" for="name">
                        {{ translate('Name')}} 
                    </label>
                    <input type="text" placeholder="{{ translate('Name')}}" id="name" name="name" class="form-control" required value="{{ $color->name }}">
                    
                </div>
                <div class="form-group mb-3">
                    <label class="col-from-label" for="code">
                        {{ translate('Color Code')}} 
                    </label>
                    <input type="text" placeholder="{{ translate('Color Code')}}" id="code" name="code" class="form-control" required value="{{ $color->code }}">
                </div>
                <div class="form-group mb-0 text-right">
                    <button type="submit" class="btn btn-primary">{{translate('Save')}}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
